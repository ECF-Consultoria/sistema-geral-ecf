import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, useMemo, useRef, useEffect } from 'react';
import {
    Building2, Search, Tag, Eye, Briefcase, ChevronLeft, ChevronRight,
    AlertCircle, Plus, ListChecks, Webhook,
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import GruposManager from '@/Components/GruposManager';

/**
 * Phase 37 Plan 37-05 — Listagem unificada do Comercial.
 *
 * Cobre TODAS as empresas (todos os setores) com filtros snake_case empilháveis,
 * 5 cards de pendência comercial (calculadas APENAS para empresas com origem
 * HubSpot — REQ-37-10) e aba de Grupos (CRUD via rotas company-groups.* admin-only,
 * reaproveita GruposManager existente).
 *
 * Lição Phase 18 aplicada: applyFilter sempre passa `{...filters}` para preservar
 * os outros 4 valores ao alterar 1.
 */

// ─── Constants pt-BR ─────────────────────────────────────────────────────────

const PENDENCIAS_LABELS = {
    sem_servico:             'Sem serviço',
    sem_valor:               'Sem valor',
    servico_nao_reconhecido: 'Serviço não reconhecido',
    sem_setor:               'Sem setor (catálogo)',
    dados_close_incompletos: 'Close incompleto',
};

const PENDENCIAS_CLS = {
    sem_servico:             'bg-red-500/10 text-red-400 border-red-500/20',
    sem_valor:               'bg-orange-500/10 text-orange-400 border-orange-500/20',
    servico_nao_reconhecido: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
    sem_setor:               'bg-sky-500/10 text-sky-400 border-sky-500/20',
    dados_close_incompletos: 'bg-fuchsia-500/10 text-fuchsia-400 border-fuchsia-500/20',
};

const SETOR_LABELS = {
    performance: 'Performance',
    publicacao:  'Publicação',
    outros:      'Outros',
};

const SETOR_CLS = {
    performance: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
    publicacao:  'bg-sky-500/15 text-sky-300 border-sky-500/25',
    outros:      'bg-white/10 text-white/60 border-white/10',
};

// ─── Helpers de UI ───────────────────────────────────────────────────────────

function OrigemBadge({ isHubspot }) {
    if (isHubspot) {
        return (
            <span title="Empresa criada via webhook HubSpot" className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full border bg-orange-500/15 text-orange-300 border-orange-500/25">
                <Webhook size={9} /> HubSpot
            </span>
        );
    }
    return (
        <span title="Empresa cadastrada manualmente (legacy)" className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full border bg-white/[0.05] text-white/55 border-white/10">
            Legacy
        </span>
    );
}

function SetorBadge({ setor }) {
    if (!setor) return <span className="text-white/30">—</span>;
    return (
        <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', SETOR_CLS[setor] ?? SETOR_CLS.outros)}>
            {SETOR_LABELS[setor] ?? setor}
        </span>
    );
}

function ServicoBadges({ contratos }) {
    if (!contratos || contratos.length === 0) {
        return <span className="text-white/30">—</span>;
    }
    const visible = contratos.slice(0, 2);
    const extra = contratos.length - 2;
    const tooltip = (ct) => {
        const nome = ct.servico?.nome ?? '—';
        const valor = formatCurrency(ct.valor_contratado);
        return `${nome} — ${valor}`;
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

function PendenciaBadges({ pendencias }) {
    if (!pendencias?.length) return <span className="text-white/30">—</span>;
    return (
        <div className="flex flex-wrap gap-1">
            {pendencias.map(p => (
                <span key={p} className={cn('inline-flex text-[10px] font-semibold px-1.5 py-0.5 rounded border', PENDENCIAS_CLS[p] ?? '')}>
                    {PENDENCIAS_LABELS[p] ?? p}
                </span>
            ))}
        </div>
    );
}

// ─── Paginação Inertia (forward/back simples) ───────────────────────────────

function Paginator({ paginator }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const prev = paginator.prev_page_url;
    const next = paginator.next_page_url;
    return (
        <div className="flex items-center justify-between border-t border-white/[0.06] px-4 py-2 bg-white/[0.02]">
            <span className="text-white/40 text-[12px]">
                Página {paginator.current_page} de {paginator.last_page} — {paginator.total} empresas
            </span>
            <div className="flex items-center gap-1">
                <Link
                    href={prev || '#'}
                    preserveScroll
                    preserveState
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !prev && 'opacity-30 pointer-events-none',
                    )}
                >
                    <ChevronLeft size={13} /> Anterior
                </Link>
                <Link
                    href={next || '#'}
                    preserveScroll
                    preserveState
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !next && 'opacity-30 pointer-events-none',
                    )}
                >
                    Próxima <ChevronRight size={13} />
                </Link>
            </div>
        </div>
    );
}

// ─── Página principal ────────────────────────────────────────────────────────

export default function EmpresasListagem({
    companies,
    filters = {},
    pendencia_counts = {},
    servico_counts = [],
    grupos = [],
    servicos_disponiveis = [],
}) {
    // Aba inicial via ?tab= (deep-link). Default = 'empresas'.
    const [tab, setTab] = useState(() => {
        const t = typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('tab')
            : null;
        return ['empresas', 'grupos'].includes(t) ? t : 'empresas';
    });

    // Search com debounce — evita request por caractere.
    const [qInput, setQInput] = useState(filters.q || '');
    const debounceRef = useRef(null);

    const applyFilter = (key, value) => {
        router.get(route('comercial.empresas.listagem'), {
            ...filters,
            [key]: value || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const onSearchChange = (e) => {
        const v = e.target.value;
        setQInput(v);
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => applyFilter('q', v), 400);
    };

    // Sincroniza qInput quando filters.q mudar (ex: voltar via back button)
    useEffect(() => {
        setQInput(filters.q || '');
    }, [filters.q]);

    const trocaTab = (next) => {
        setTab(next);
        // Sincroniza ?tab= sem refetch — só atualiza URL
        if (typeof window !== 'undefined' && window.history?.replaceState) {
            const u = new URL(window.location.href);
            u.searchParams.set('tab', next);
            window.history.replaceState({}, '', u.toString());
        }
    };

    const totalGruposEmpresas = useMemo(
        () => companies.data?.length ?? 0,
        [companies.data]
    );

    return (
        <AppLayout title="Comercial · Empresas">
            <div className="space-y-4">
                {/* Tabs */}
                <div className="flex items-center gap-2 border-b border-white/[0.06]">
                    <button
                        onClick={() => trocaTab('empresas')}
                        className={cn(
                            'px-4 py-2 text-[13px] font-medium border-b-2 transition-colors',
                            tab === 'empresas'
                                ? 'text-ecf-yellow border-ecf-yellow'
                                : 'text-white/50 border-transparent hover:text-white/80'
                        )}
                    >
                        <span className="inline-flex items-center gap-2">
                            <Building2 size={14} /> Empresas
                        </span>
                    </button>
                    <button
                        onClick={() => trocaTab('grupos')}
                        className={cn(
                            'px-4 py-2 text-[13px] font-medium border-b-2 transition-colors',
                            tab === 'grupos'
                                ? 'text-ecf-yellow border-ecf-yellow'
                                : 'text-white/50 border-transparent hover:text-white/80'
                        )}
                    >
                        <span className="inline-flex items-center gap-2">
                            <Tag size={14} /> Grupos
                            <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-white/10 text-white/70 text-[10px] font-bold">
                                {grupos.length}
                            </span>
                        </span>
                    </button>
                </div>

                {tab === 'empresas' && (
                    <>
                        {/* Linha de filtros: busca + setor + ordem + CTA cadastrar */}
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative flex-1 min-w-[240px] max-w-md">
                                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                                <Input
                                    value={qInput}
                                    onChange={onSearchChange}
                                    placeholder="Buscar por nome ou CNPJ..."
                                    className="pl-9"
                                />
                            </div>
                            <select
                                value={filters.setor || ''}
                                onChange={e => applyFilter('setor', e.target.value)}
                                className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="" className="bg-[#0f1116]">Todos os setores</option>
                                <option value="performance" className="bg-[#0f1116]">Performance</option>
                                <option value="publicacao" className="bg-[#0f1116]">Publicação</option>
                                <option value="outros" className="bg-[#0f1116]">Outros</option>
                            </select>
                            <select
                                value={filters.ordem || 'recentes'}
                                onChange={e => applyFilter('ordem', e.target.value)}
                                className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="recentes" className="bg-[#0f1116]">Mais recentes</option>
                                <option value="antigas" className="bg-[#0f1116]">Mais antigas</option>
                            </select>
                            <div className="flex-1" />
                            <Link href={route('comercial.empresas.novo')}>
                                <Button className="bg-ecf-yellow text-black hover:bg-ecf-yellow/90">
                                    <Plus className="h-4 w-4 mr-1" /> Cadastrar empresa
                                </Button>
                            </Link>
                        </div>

                        {/* 5 cards de pendência comercial (clicáveis) */}
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-2">
                            {Object.entries(PENDENCIAS_LABELS).map(([key, label]) => {
                                const active = filters.pendencia === key;
                                const count = pendencia_counts?.[key] ?? 0;
                                return (
                                    <button
                                        key={key}
                                        onClick={() => applyFilter('pendencia', active ? null : key)}
                                        className={cn(
                                            'rounded-xl border px-3 py-3 text-left transition-colors',
                                            active
                                                ? 'border-ecf-yellow bg-ecf-yellow/[0.06]'
                                                : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.04]'
                                        )}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="text-2xl font-bold tabular-nums text-white">{count}</div>
                                            <AlertCircle size={14} className={active ? 'text-ecf-yellow' : 'text-white/30'} />
                                        </div>
                                        <div className="text-[12px] text-white/60 mt-0.5">{label}</div>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Chips de serviço (clicáveis, filtram servico=X) */}
                        {servico_counts.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                <button
                                    onClick={() => applyFilter('servico', null)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] transition-colors',
                                        !filters.servico
                                            ? 'border-ecf-yellow text-ecf-yellow bg-ecf-yellow/[0.06]'
                                            : 'border-white/10 text-white/60 hover:bg-white/[0.04]'
                                    )}
                                >
                                    Todos os serviços
                                </button>
                                {servico_counts.map(s => {
                                    const active = String(filters.servico) === String(s.id);
                                    return (
                                        <button
                                            key={s.id}
                                            onClick={() => applyFilter('servico', active ? null : String(s.id))}
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] transition-colors',
                                                active
                                                    ? 'border-ecf-yellow text-ecf-yellow bg-ecf-yellow/[0.06]'
                                                    : 'border-white/10 text-white/60 hover:bg-white/[0.04]'
                                            )}
                                        >
                                            {s.nome}
                                            <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">{s.total}</Badge>
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {/* Tabela */}
                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Empresa</TableHead>
                                            <TableHead>Origem</TableHead>
                                            <TableHead>Serviços</TableHead>
                                            <TableHead>Setor</TableHead>
                                            <TableHead>Pendências</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {companies.data?.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={6} className="text-center text-white/40 py-8">
                                                    Nenhuma empresa encontrada com os filtros aplicados.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {companies.data?.map(c => (
                                            <TableRow key={c.id}>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <span className="text-white font-medium">{c.name}</span>
                                                        {c.cnpj && <span className="text-white/40 text-[11px] font-mono">{c.cnpj}</span>}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <OrigemBadge isHubspot={c.is_origem_hubspot} />
                                                </TableCell>
                                                <TableCell>
                                                    <ServicoBadges contratos={c.contratos_servico || []} />
                                                </TableCell>
                                                <TableCell>
                                                    <SetorBadge setor={c.setor_dominante} />
                                                </TableCell>
                                                <TableCell>
                                                    <PendenciaBadges pendencias={c.pendencias_comerciais} />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="inline-flex items-center gap-1">
                                                        <Link href={route('comercial.atribuir-servico', c.id)}>
                                                            <Button size="sm" variant="ghost" title="Atribuir serviço">
                                                                <Briefcase size={13} />
                                                            </Button>
                                                        </Link>
                                                        <Link href={route('companies.show', c.id)}>
                                                            <Button size="sm" variant="ghost" title="Ver empresa">
                                                                <Eye size={13} />
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                <Paginator paginator={companies} />
                            </CardContent>
                        </Card>
                    </>
                )}

                {tab === 'grupos' && (
                    <Card>
                        <CardContent className="p-4 space-y-4">
                            <GruposManager
                                grupos={grupos}
                                companies={companies.data || []}
                                servicos={servicos_disponiveis}
                            />
                            <p className="text-white/40 text-[11px] leading-relaxed border-t border-white/[0.06] pt-3">
                                <ListChecks size={11} className="inline mr-1" />
                                Apenas {totalGruposEmpresas} empresa(s) da página atual aparecem como candidatas para vincular. Use os filtros da aba "Empresas" para refinar antes de gerenciar grupos.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
