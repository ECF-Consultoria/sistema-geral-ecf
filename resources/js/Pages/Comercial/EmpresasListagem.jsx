import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import {
    Building2, Search, Tag, Eye, Briefcase, ChevronLeft, ChevronRight,
    AlertCircle, Plus, ListChecks, Webhook, Pencil, Trash2, Save, Info,
} from 'lucide-react';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
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
    // Fase 114-02 — pendências novas, aditivas, só para origem HubSpot.
    sem_contato:             'Sem contato',
    valor_revisar:           'Revisar valor',
    possivel_duplicidade:    'Possível duplicidade',
};

const PENDENCIAS_CLS = {
    sem_servico:             'bg-red-500/10 text-red-400 border-red-500/20',
    sem_valor:               'bg-orange-500/10 text-orange-400 border-orange-500/20',
    servico_nao_reconhecido: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
    sem_setor:               'bg-sky-500/10 text-sky-400 border-sky-500/20',
    sem_contato:             'bg-slate-500/10 text-slate-300 border-slate-500/20',
    valor_revisar:           'bg-yellow-500/10 text-yellow-300 border-yellow-500/20',
    possivel_duplicidade:    'bg-rose-500/10 text-rose-400 border-rose-500/20',
};

// Confiança do valor HubSpot (bloco de valor por contrato) — cor semântica.
const CONFIANCA_CLS = {
    high:   'text-emerald-400',
    medium: 'text-amber-300',
    low:    'text-red-400',
};

const CONFIANCA_LABEL = {
    high:   'Alta',
    medium: 'Média',
    low:    'Baixa',
};

const FREQUENCIA_LABEL = {
    monthly:  'mensal',
    annually: 'anual',
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

function PendenciaBadges({ pendencias, detalhes = {} }) {
    if (!pendencias?.length) return <span className="text-white/30">—</span>;
    const tooltipFor = (p) => {
        const itens = detalhes?.[p];
        const labelBase = PENDENCIAS_LABELS[p] ?? p;
        if (!itens || itens.length === 0) return labelBase;
        return `${labelBase}: ${itens.join(', ')}`;
    };
    return (
        <div className="flex flex-wrap gap-1">
            {pendencias.map(p => (
                <span
                    key={p}
                    title={tooltipFor(p)}
                    className={cn('inline-flex text-[10px] font-semibold px-1.5 py-0.5 rounded border cursor-help', PENDENCIAS_CLS[p] ?? '')}
                >
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

// ─── Modal de edição da empresa (close fields + contratos) ────────────────────

function EditarEmpresaModal({ empresa, open, onClose }) {
    // Quick task 260805-eqk — os campos do close (nicho/dor/faturamento_mensal)
    // saíram junto com a pendência "Close incompleto".
    const empresaForm = useForm({
        name:               empresa?.name ?? '',
        email_cliente:      empresa?.email_cliente ?? '',
        telefone:           empresa?.telefone ?? '',
    });

    // Estado local dos contratos (com edição inline de valor_contratado). O
    // backend aceita PUT por contrato individual, então salvamos um por vez ao
    // clicar no botão "salvar valor". Evita merge complexo em transação.
    const [contratos, setContratos] = useState(() => empresa?.contratos_servico ?? []);

    useEffect(() => {
        if (open && empresa) {
            empresaForm.setData({
                name:               empresa.name ?? '',
                email_cliente:      empresa.email_cliente ?? '',
                telefone:           empresa.telefone ?? '',
            });
            setContratos(empresa.contratos_servico ?? []);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, empresa?.id]);

    if (!empresa) return null;

    const salvarEmpresa = (e) => {
        e.preventDefault();
        empresaForm.put(route('comercial.empresas.update', empresa.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const editarContratoValor = (contratoId, novoValor) => {
        setContratos(prev => prev.map(c => c.id === contratoId ? { ...c, valor_contratado: novoValor } : c));
    };

    const salvarContrato = (contrato) => {
        router.put(route('comercial.empresas.contratos.update', [empresa.id, contrato.id]), {
            valor_contratado: parseFloat(contrato.valor_contratado) || 0,
            data_contratacao: contrato.data_contratacao || new Date().toISOString().slice(0, 10),
            data_vencimento:  contrato.data_vencimento || null,
            ativo:            true,
        }, { preserveScroll: true });
    };

    const desativarContrato = (contrato) => {
        if (!confirm(`Desativar o contrato de "${contrato.servico?.nome ?? '—'}"?`)) return;
        router.delete(route('comercial.empresas.contratos.destroy', [empresa.id, contrato.id]), {
            preserveScroll: true,
            onSuccess: () => setContratos(prev => prev.filter(c => c.id !== contrato.id)),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
            <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Editar empresa — {empresa.name}</DialogTitle>
                </DialogHeader>

                <form onSubmit={salvarEmpresa} className="space-y-6 mt-2">
                    {/* ── Bloco 1: identificação básica ─────────────────────── */}
                    <div className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Nome *</Label>
                            <Input value={empresaForm.data.name} onChange={e => empresaForm.setData('name', e.target.value)} required />
                            {empresaForm.errors.name && <p className="text-destructive text-xs">{empresaForm.errors.name}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Email do cliente</Label>
                                <Input type="email" value={empresaForm.data.email_cliente} onChange={e => empresaForm.setData('email_cliente', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Telefone</Label>
                                <Input value={empresaForm.data.telefone} onChange={e => empresaForm.setData('telefone', e.target.value)} />
                            </div>
                        </div>
                    </div>

                    {/* ── Bloco 2: contratos ativos (editar valor / desativar) ── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Briefcase size={14} className="text-ecf-yellow/70" />
                                <h3 className="text-white/85 text-sm font-semibold">Contratos ativos</h3>
                            </div>
                            <Link
                                href={route('comercial.atribuir-servico', empresa.id) + '?return_to=' + encodeURIComponent(typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/comercial/empresas/listagem')}
                                className="inline-flex items-center gap-1 text-[11px] text-ecf-yellow hover:underline"
                            >
                                <Plus size={12} /> novo contrato
                            </Link>
                        </div>
                        {contratos.length === 0 && (
                            <p className="text-white/40 text-[12px]">Nenhum contrato ativo. Clique em "novo contrato" para atribuir.</p>
                        )}
                        {contratos.map(ct => (
                            <div key={ct.id} className="flex items-center gap-2 rounded-lg bg-white/[0.03] px-3 py-2">
                                <div className="flex-1 min-w-0">
                                    <div className="text-white/85 text-[13px] truncate">{ct.servico?.nome ?? '—'}</div>
                                    <div className="text-white/40 text-[10px]">
                                        {ct.servico?.setor ? SETOR_LABELS[ct.servico.setor] ?? ct.servico.setor : '—'}
                                    </div>
                                </div>
                                <div className="w-32">
                                    <Input
                                        type="number" step="0.01" min="0"
                                        value={ct.valor_contratado ?? ''}
                                        onChange={e => editarContratoValor(ct.id, e.target.value)}
                                        className="h-8 text-[12px]"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={() => salvarContrato(ct)}
                                    title="Salvar valor"
                                    className="p-1.5 rounded-md border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/15"
                                >
                                    <Save size={13} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => desativarContrato(ct)}
                                    title="Desativar contrato"
                                    className="p-1.5 rounded-md border border-red-500/30 text-red-400 hover:bg-red-500/15"
                                >
                                    <Trash2 size={13} />
                                </button>
                            </div>
                        ))}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Fechar</Button>
                        <Button type="submit" disabled={empresaForm.processing}>
                            {empresaForm.processing ? 'Salvando…' : 'Salvar empresa'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ─── Modal de detalhes HubSpot (contato/observação/IDs + bloco de valor) ─────
// Fase 114-02 — detalhe leve por linha, só para empresas de origem HubSpot.
// Espelha o padrão do EditarEmpresaModal (Dialog reusado), mas é só leitura.

function DetalheHubspotModal({ empresa, open, onClose }) {
    if (!empresa) return null;

    const contratos = empresa.contratos_servico ?? [];

    return (
        <Dialog open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
            <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Detalhes HubSpot — {empresa.name}</DialogTitle>
                </DialogHeader>

                <div className="space-y-4 mt-2">
                    {/* ── Contato ────────────────────────────────────────── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-1">
                        <h3 className="text-white/85 text-sm font-semibold mb-2">Contato</h3>
                        <p className="text-white/85 text-[13px]">
                            {empresa.nome_contato || <span className="text-white/30">Sem contato</span>}
                        </p>
                        {empresa.cargo_contato && (
                            <p className="text-white/40 text-[12px]">{empresa.cargo_contato}</p>
                        )}
                        {/* Quick task 260805-eqk — a origem do lead nasce no
                            contato, por isso fica junto dele. */}
                        <p className="text-white/40 text-[12px] pt-1">
                            Origem do lead: <span className="text-white/70">{empresa.origem_lead || '—'}</span>
                        </p>
                    </div>

                    {/* ── Observações (Notes do deal HubSpot) ────────────── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
                        <h3 className="text-white/85 text-sm font-semibold">Observações</h3>
                        {(empresa.hubspot_notas ?? []).length === 0 ? (
                            <p className="text-white/60 text-[12px]">—</p>
                        ) : (
                            (empresa.hubspot_notas ?? []).map((nota, idx) => {
                                // Armadilha Rollup: computar tudo DENTRO do callback.
                                const chave = nota?.id ? `nota-${nota.id}` : `nota-idx-${idx}`;
                                const data  = nota?.timestamp ? formatDate(nota.timestamp) : null;
                                return (
                                    <div key={chave}>
                                        {data && (
                                            <p className="text-white/40 text-[11px] uppercase tracking-wide mb-0.5">{data}</p>
                                        )}
                                        <p className="text-white/60 text-[12px] whitespace-pre-wrap">{nota?.body || '—'}</p>
                                    </div>
                                );
                            })
                        )}
                    </div>

                    {/* ── SPIN (Situação/Problema/Implicação/Necessidade) ── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
                        <h3 className="text-white/85 text-sm font-semibold">SPIN</h3>
                        {[
                            ['Situação atual do cliente', empresa.spin?.situacao],
                            ['Problema Principal Identificado', empresa.spin?.problema],
                            ['Implicação do Problema', empresa.spin?.implicacao],
                            ['Necessidade de Solução', empresa.spin?.necessidade],
                        ].map(([label, valor]) => (
                            <div key={label}>
                                <p className="text-white/40 text-[11px] uppercase tracking-wide mb-0.5">{label}</p>
                                <p className="text-white/70 text-[12px] whitespace-pre-wrap">{valor || '—'}</p>
                            </div>
                        ))}
                    </div>

                    {/* ── IDs HubSpot ────────────────────────────────────── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-1">
                        <h3 className="text-white/85 text-sm font-semibold mb-2">IDs HubSpot</h3>
                        <p className="text-white/60 text-[12px] font-mono">
                            Deal: {empresa.hubspot_deal_id || '—'}
                        </p>
                        <p className="text-white/60 text-[12px] font-mono">
                            Company: {empresa.hubspot_company_id || '—'}
                        </p>
                    </div>

                    {/* ── Valor por contrato ─────────────────────────────── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
                        <h3 className="text-white/85 text-sm font-semibold">Valor por contrato</h3>
                        {contratos.length === 0 && (
                            <p className="text-white/40 text-[12px]">Nenhum contrato ativo.</p>
                        )}
                        {contratos.map(ct => {
                            const confidence = ct.hubspot_valor_confidence;
                            const frequenciaLabel = FREQUENCIA_LABEL[ct.hubspot_billing_frequency] ?? ct.hubspot_billing_frequency ?? '—';
                            return (
                                <div key={ct.id} className="rounded-lg bg-white/[0.03] px-3 py-2 space-y-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-white/85 text-[13px]">{ct.servico?.nome ?? '—'}</span>
                                        <span className="text-white font-semibold text-[13px]">{formatCurrency(ct.valor_contratado)}</span>
                                    </div>
                                    {ct.hubspot_valor_original != null && (
                                        <p className="text-white/40 text-[11px]">
                                            Original HubSpot: {formatCurrency(ct.hubspot_valor_original)} ({frequenciaLabel})
                                        </p>
                                    )}
                                    {ct.hubspot_billing_frequency && (
                                        <p className="text-white/40 text-[11px]">
                                            Frequência: {frequenciaLabel}
                                        </p>
                                    )}
                                    {confidence && (
                                        <p className="text-[11px]">
                                            Confiança: <span className={cn('font-semibold', CONFIANCA_CLS[confidence] ?? 'text-white/60')}>
                                                {CONFIANCA_LABEL[confidence] ?? confidence}
                                            </span>
                                        </p>
                                    )}
                                    {ct.hubspot_valor_warning && (
                                        <p className="text-amber-300 text-[11px] flex items-start gap-1">
                                            <AlertCircle size={11} className="mt-0.5 shrink-0" /> {ct.hubspot_valor_warning}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>Fechar</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ─── Página principal ────────────────────────────────────────────────────────

export default function EmpresasListagem({
    companies,
    companies_para_grupos = [],
    filters = {},
    pendencia_counts = {},
    servico_counts = [],
    grupos = [],
    servicos_disponiveis = [],
}) {
    // Estado da modal de edição (Bug 1+2 hotfix).
    const [editandoEmpresa, setEditandoEmpresa] = useState(null);
    const abrirEdicao = (c) => setEditandoEmpresa(c);
    const fecharEdicao = () => setEditandoEmpresa(null);

    // Estado da modal de detalhes HubSpot (Fase 114-02) — mesmo padrão da edição.
    const [detalheEmpresa, setDetalheEmpresa] = useState(null);
    const abrirDetalhe = (c) => setDetalheEmpresa(c);
    const fecharDetalhe = () => setDetalheEmpresa(null);

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

                        {/* 8 cards de pendência comercial (clicáveis) — 5 atuais + 3 novas (114-02) */}
                        <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
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
                                            <TableHead>Cadastrado em</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {companies.data?.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={7} className="text-center text-white/40 py-8">
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
                                                    <PendenciaBadges pendencias={c.pendencias_comerciais} detalhes={c.pendencias_detalhes} />
                                                </TableCell>
                                                <TableCell>
                                                    {/* Quando o lead entrou no sistema (data + hora). A lista ja e
                                                        ordenada por esse campo pelo seletor "Mais recentes/antigas". */}
                                                    <span className="text-white/60 text-[12px] whitespace-nowrap">
                                                        {c.created_at ? formatDateTime(c.created_at) : '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="inline-flex items-center gap-1">
                                                        {/* Detalhes HubSpot só para empresas de origem HubSpot —
                                                            empresa legada não tem esses dados (evita botão morto). */}
                                                        {c.is_origem_hubspot && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                title="Detalhes HubSpot (contato, valor, IDs)"
                                                                onClick={() => abrirDetalhe(c)}
                                                            >
                                                                <Info size={13} />
                                                            </Button>
                                                        )}
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            title="Editar empresa (close + contratos)"
                                                            onClick={() => abrirEdicao(c)}
                                                        >
                                                            <Pencil size={13} />
                                                        </Button>
                                                        <Link href={route('comercial.atribuir-servico', c.id) + '?return_to=' + encodeURIComponent(typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/comercial/empresas/listagem')}>
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
                            {/* Phase 37 fix pós-deploy — usa companies_para_grupos (TODAS
                                as ativas) em vez de companies.data (paginado/filtrado),
                                senão membros de grupos não aparecem mesmo com count correto. */}
                            <GruposManager
                                grupos={grupos}
                                companies={companies_para_grupos}
                                servicos={servicos_disponiveis}
                            />
                            <p className="text-white/40 text-[11px] leading-relaxed border-t border-white/[0.06] pt-3">
                                <ListChecks size={11} className="inline mr-1" />
                                {companies_para_grupos.length} empresa(s) ativas disponíveis para vincular.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* Modal de edição da empresa (Bug 1+2 hotfix 2026-06-19) */}
            <EditarEmpresaModal
                empresa={editandoEmpresa}
                open={!!editandoEmpresa}
                onClose={fecharEdicao}
            />

            {/* Modal de detalhes HubSpot (Fase 114-02) */}
            <DetalheHubspotModal
                empresa={detalheEmpresa}
                open={!!detalheEmpresa}
                onClose={fecharDetalhe}
            />
        </AppLayout>
    );
}
