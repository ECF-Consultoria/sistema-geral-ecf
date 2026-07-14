import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Building2, Check, Copy, Send, Star } from 'lucide-react';
import { formatCurrency, formatDate, cn } from '@/lib/utils';

// ─── Página "Empresas" da Shopee (Phase 75 Plan 75-05, DEC-3/4/5) ────────────
// Versão ENXUTA de Companies/Index.jsx: SEM colunas de métrica/cust_id/grant,
// SEM edição/exclusão de empresa. As únicas ações são atribuir responsável
// (bulk-assign) e gerar NPS (motor existente). Empresas atendidas só na Shopee
// ainda não têm API/métrica — a aba serve exclusivamente ao fluxo de NPS.
// Rotas: shopee.empresas.* (gated por permission:shopee.empresas) + nps.generate.

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

export default function Empresas({ companies = [], estrategistas = [], analistas = [], grupos = [] }) {
    const { flash } = usePage().props;

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

    // ── Gerar NPS por linha (reaproveita POST /nps/generate) ─────────────────
    // useForm.post com transform garante o company_id certo no submit (sem
    // depender de setData assíncrono). O link volta em flash.nps_link.
    const npsForm = useForm({ company_id: '', template_id: '' });
    const gerarNps = (company) => {
        npsForm.transform(() => ({ company_id: company.id, template_id: '' }));
        npsForm.post(route('nps.generate'), { preserveScroll: true });
    };

    const [npsLink, setNpsLink] = useState('');
    const [npsDialog, setNpsDialog] = useState(false);
    const [npsCopied, setNpsCopied] = useState(false);
    useEffect(() => {
        if (flash?.nps_link) {
            setNpsLink(flash.nps_link);
            setNpsDialog(true);
        }
    }, [flash?.nps_link]);

    const copiarNps = () => {
        navigator.clipboard.writeText(npsLink).then(() => {
            setNpsCopied(true);
            setTimeout(() => setNpsCopied(false), 2000);
        });
    };

    const TABS = [
        { key: 'todas',      label: `Todas (${totalAtivas})` },
        { key: 'pendencias', label: `Pendências (${pendentes.length})` },
    ];

    // Botão "Gerar NPS" reutilizado nas duas abas.
    const NpsButton = ({ company }) => (
        <Button
            size="sm"
            variant="outline"
            className="gap-1.5 text-[12px] border-ecf-yellow/30 text-ecf-yellow hover:bg-ecf-yellow/10"
            onClick={() => gerarNps(company)}
            disabled={npsForm.processing}
            title="Gerar link de NPS para esta empresa"
        >
            <Star className="h-3.5 w-3.5" /> Gerar NPS
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
                                                <TableCell className="text-xs text-white/60">{c.email_cliente || <span className="text-white/25">—</span>}</TableCell>
                                                <TableCell><PendenciaBadges pendencias={c.pendencias} /></TableCell>
                                                <TableCell>
                                                    <Badge variant={c.active ? 'success' : 'destructive'}>{c.active ? 'Ativa' : 'Inativa'}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <NpsButton company={c} />
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {filtered.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={10} className="text-center text-muted-foreground py-8">
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
                                                        <NpsButton company={c} />
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

            {/* Modal do link NPS gerado (captura flash.nps_link) */}
            <Dialog open={npsDialog} onOpenChange={setNpsDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Send size={16} className="text-ecf-yellow/70" /> Link de NPS gerado
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p className="text-white/55 text-[13px]">
                            Envie este link ao cliente para coletar a avaliação (NPS). O link é único desta empresa.
                        </p>
                        <div className="flex gap-2">
                            <input readOnly value={npsLink} onClick={e => e.target.select()} className="flex-1 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[12px] text-white/70 font-mono focus:outline-none cursor-text" />
                            <Button type="button" size="sm" variant="outline" className="shrink-0 gap-1.5 text-[12px]" onClick={copiarNps}>
                                {npsCopied ? <><Check size={13} className="text-emerald-400" /> Copiado!</> : <><Copy size={13} /> Copiar</>}
                            </Button>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setNpsDialog(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
