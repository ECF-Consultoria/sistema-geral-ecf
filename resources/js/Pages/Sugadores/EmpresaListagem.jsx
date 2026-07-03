import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    ArrowLeft, Building2, Tag, Megaphone, Copy, Check, Loader2,
    PlayCircle, Settings, SlidersHorizontal, AlertTriangle,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    Table, TableHeader, TableBody, TableRow, TableHead, TableCell,
} from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';

// Phase 52 Wave 3.5 (52-03B) — Drilldown "por empresa": lista os sugadores
// pendentes/em_acao de UMA empresa. Restaura o alvo do click no CompanyCard
// (removido junto com a tabela lista global na Wave 2 — Opcao Z) e reusa os
// endpoints da Wave 1: sugadores.mlbs-hint (A5, per-linha) + sugadores.bulk-copy-mlbs
// (A6, em massa). O ConfigResumoCard e o cronometro de 30s replicam o padrao
// da Show.jsx da Wave 3 (nao ha coluna "empresa" — A4 absorvida).

// ─── Constantes de UI ──────────────────────────────────────────────────────
const STATUS_LABELS = {
    pendente: 'Pendente',
    em_acao:  'Em ação',
};
const STATUS_BADGE = {
    pendente: 'bg-red-500/15 text-red-300 border-red-500/30',
    em_acao:  'bg-amber-500/15 text-amber-300 border-amber-500/30',
};
const TIPO_ICONS = { adgroup: Tag, campanha: Megaphone };
const TIPO_LABELS = { adgroup: 'Adgroup', campanha: 'Campanha' };

// ─── Helpers ───────────────────────────────────────────────────────────────
const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
// Aceita "YYYY-MM-DD" e ISO datetime (cast 'date' do Laravel).
// Fixa em meia-noite local pra nao cair no dia anterior por timezone.
const fmtDate = (d) => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR') : '—';

// Duplicado de Index.jsx/Show.jsx propositalmente: extrair pra @/lib/utils fica
// quando houver 4+ consumers e uma decisao explicita sobre fallback intranet.
const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        // Fallback obrigatorio pra intranet HTTP (navigator.clipboard exige secure context).
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
};

// ─── Componentes locais ────────────────────────────────────────────────────

function StatusBadge({ status }) {
    return (
        <span className={cn(
            'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border',
            STATUS_BADGE[status] || 'bg-white/[0.05] text-white/60 border-white/[0.08]',
        )}>
            {STATUS_LABELS[status] || status}
        </span>
    );
}

/**
 * ConfigResumoCard — copia do padrao da Show.jsx Wave 3.
 * Renderiza resumo compacto da SugadorConfig da empresa + botoes
 * "Configurar" (rota sugadores.config.show) e "Rodar analise" com
 * cronometro fixo de 30s. Escondido quando canManage=false.
 */
function ConfigResumoCard({ config, companyId, canManage, canAnalyze, analyzing, elapsed, onRodarAnalise }) {
    const hasConfig = config != null;
    return (
        <div className="card-ecf rounded-xl p-4">
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                    <SlidersHorizontal size={13} className="text-white/40" />
                    <h3 className="text-white/90 font-display font-semibold text-sm">Configuração</h3>
                </div>
                {hasConfig ? (
                    config.ativo
                        ? <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">ATIVA</span>
                        : <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-zinc-500/15 text-zinc-300 border border-zinc-500/30">INATIVA</span>
                ) : (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-white/[0.04] text-white/50 border border-white/[0.08]">DEFAULT</span>
                )}
            </div>

            {hasConfig ? (
                <dl className="space-y-1.5 text-[11px]">
                    {config.dias_analise != null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-white/50">Janela</dt>
                            <dd className="text-white/80 tabular-nums">{config.dias_analise} dias</dd>
                        </div>
                    )}
                    {config.gasto_minimo_sem_venda != null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-white/50">Gasto mín. s/ venda</dt>
                            <dd className="text-white/80 tabular-nums">R$ {config.gasto_minimo_sem_venda}</dd>
                        </div>
                    )}
                    {config.cpc_maximo != null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-white/50">CPC máx.</dt>
                            <dd className="text-white/80 tabular-nums">R$ {config.cpc_maximo}</dd>
                        </div>
                    )}
                    {config.acos_maximo_pct != null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-white/50">ACOS máx.</dt>
                            <dd className="text-white/80 tabular-nums">{config.acos_maximo_pct}%</dd>
                        </div>
                    )}
                    {config.cliques_minimos_sem_venda != null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-white/50">Cliques mín. s/ venda</dt>
                            <dd className="text-white/80 tabular-nums">{config.cliques_minimos_sem_venda}</dd>
                        </div>
                    )}
                </dl>
            ) : (
                <p className="text-white/40 text-[11px] leading-relaxed">
                    Nenhuma configuração personalizada — usando defaults do sistema.
                </p>
            )}

            {canManage && companyId && (
                <div className="mt-3 space-y-2 pt-3 border-t border-white/[0.06]">
                    <button
                        type="button"
                        onClick={() => router.visit(route('sugadores.config.show', companyId))}
                        disabled={analyzing}
                        className="w-full inline-flex items-center justify-center gap-1.5 h-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] disabled:opacity-50 disabled:cursor-not-allowed text-[12px] font-medium"
                        title="Editar thresholds de detecção para esta empresa"
                    >
                        <Settings size={12} />
                        Configurar
                    </button>
                    {canAnalyze && (
                        <>
                            <button
                                type="button"
                                onClick={onRodarAnalise}
                                disabled={analyzing}
                                className="w-full inline-flex items-center justify-center gap-1.5 h-8 rounded-lg bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/25 disabled:opacity-70 disabled:cursor-not-allowed text-[12px] font-semibold"
                                title="Enfileira analise ML para esta empresa (~30s)"
                            >
                                {analyzing
                                    ? <><Loader2 size={12} className="animate-spin" /> Analisando... {elapsed}s</>
                                    : <><PlayCircle size={12} /> Rodar análise</>}
                            </button>
                            {analyzing && (
                                <p className="text-white/40 text-[10px] text-center leading-tight">
                                    Job enfileirado. Resultados atualizam automaticamente.
                                </p>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

// ─── Pagina principal ──────────────────────────────────────────────────────

export default function SugadoresEmpresaListagem({
    company,
    sugadores = [],
    sugador_config = null,
    can_manage_config = false,
    can_analyze = false,
    // Phase 54-01 — filtro de periodo enviado pelo backend porEmpresa.
    // periodo = valor efetivo em uso (default 'hoje' garantido pelo controller).
    // periodo_presets = [{ value, label }] pronto pro <select> nativo.
    periodo = 'hoje',
    periodo_presets = [],
}) {
    // ─── Selecao multipla (para acoes em massa — A6) ────────────────────────
    const [selectedIds, setSelectedIds] = useState(() => new Set());

    // ─── Cronometro do "Rodar analise" per-empresa (padrao Show.jsx Wave 3) ─
    // 30s fixos client-side: mostra progresso, dispara router.reload no fim.
    // Se o user navegar antes dos 30s, o job continua na fila (background).
    const [analyzing, setAnalyzing] = useState(false);
    const [elapsed, setElapsed] = useState(0);

    useEffect(() => {
        if (!analyzing) return;
        const tick = setInterval(() => {
            setElapsed(prev => {
                const next = prev + 1;
                if (next >= 30) {
                    clearInterval(tick);
                    router.reload({ only: ['sugadores'], preserveScroll: true });
                    setAnalyzing(false);
                    return 0;
                }
                return next;
            });
        }, 1000);
        return () => clearInterval(tick);
    }, [analyzing]);

    function rodarAnalise() {
        if (!company?.id) return;
        setAnalyzing(true);
        setElapsed(0);
        router.post(route('sugadores.analyze-company', company.id), {}, {
            preserveScroll: true,
            onError: () => {
                setAnalyzing(false);
                setElapsed(0);
            },
        });
    }

    // ─── Phase 54-02 (B1) — Filtro de periodo persistido em query string ────
    // Pattern canonico: Portfolio/Carteiras.jsx:25-30 (applyPeriod + preserveState/preserveScroll).
    // Backend porEmpresa (Wave 1) recebe ?periodo=hoje|7d|30d|todos e ecoa em periodo + periodo_presets.
    function aplicarPeriodo(value) {
        if (!company?.id) return;
        router.get(
            route('sugadores.empresa.listagem', company.id),
            { periodo: value },
            { preserveState: true, preserveScroll: true },
        );
    }

    // ─── Estados de feedback por linha (copiar MLBs por sugador — A5) ──────
    const [copyingId, setCopyingId] = useState(null);
    const [copiedFeedback, setCopiedFeedback] = useState({}); // { [sugadorId]: {ok, total, error} }

    async function copiarMlbsLinha(sugadorId) {
        setCopyingId(sugadorId);
        try {
            const r = await fetch(route('sugadores.mlbs-hint', sugadorId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                setCopiedFeedback(p => ({ ...p, [sugadorId]: { error: j.reason || `Erro ${r.status}` } }));
                return;
            }
            const mlbs = j.mlbs || [];
            if (!mlbs.length) {
                setCopiedFeedback(p => ({ ...p, [sugadorId]: { total: 0, error: 'Sem MLBs' } }));
                return;
            }
            const ok = await copyToClipboard(mlbs.join(','));
            setCopiedFeedback(p => ({ ...p, [sugadorId]: { total: j.total, ok } }));
        } catch {
            setCopiedFeedback(p => ({ ...p, [sugadorId]: { error: 'Falha de rede' } }));
        } finally {
            setCopyingId(null);
            setTimeout(() => setCopiedFeedback(p => {
                const n = { ...p }; delete n[sugadorId]; return n;
            }), 4000);
        }
    }

    // ─── Bulk: copiar MLBs dos selecionados (A6) ────────────────────────────
    const [bulkCopying, setBulkCopying] = useState(false);
    const [bulkFeedback, setBulkFeedback] = useState(null); // {ok, total, processados, error}

    async function copiarMlbsBulk() {
        const ids = Array.from(selectedIds);
        if (!ids.length) return;
        setBulkCopying(true);
        setBulkFeedback(null);
        try {
            const r = await fetch(route('sugadores.bulk-copy-mlbs'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                                    || window?.Laravel?.csrfToken
                                    || '',
                },
                body: JSON.stringify({ sugador_ids: ids }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                setBulkFeedback({ error: j.message || `Erro ${r.status}` });
                return;
            }
            const mlbs = j.mlbs || [];
            if (!mlbs.length) {
                setBulkFeedback({ error: 'Nenhum MLB encontrado para os sugadores selecionados.' });
                return;
            }
            const ok = await copyToClipboard(mlbs.join(','));
            setBulkFeedback({ ok, total: j.total, processados: j.sugadores_processados });
        } catch {
            setBulkFeedback({ error: 'Falha de rede' });
        } finally {
            setBulkCopying(false);
            // Mensagens ficam 5s pra usuario ler.
            setTimeout(() => setBulkFeedback(null), 5000);
        }
    }

    // ─── Bulk move (reusa sugadores.bulk-move da fase pre-52) ───────────────
    // Marca todos os selecionados como em_acao — sem escolha de campanha destino
    // aqui (esse fluxo continua no Show individual via MoveToSgiModal).
    // Simplifica: usamos updateStatus loop nao — bulkMove exige campanha
    // destino no schema. Deixamos "marcar em ação" via bulk-move suprimido:
    // vamos usar PATCH updateStatus por sugador em serie (JS), ja' que nao ha
    // endpoint bulk-status. Mantenho simples: botao vira link "Ver detalhes"
    // sem bulk status — nao regride nada da Wave 2.

    // ─── Seleção ─────────────────────────────────────────────────────────────
    function toggleOne(id) {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }
    function toggleAllVisible() {
        // So sugadores tipo=adgroup entram no bulk (campanha nao tem MLBs pra copiar).
        const elegiveis = sugadores.filter(s => s.tipo === 'adgroup').map(s => s.id);
        setSelectedIds(prev => {
            const allSelected = elegiveis.every(id => prev.has(id));
            return allSelected ? new Set() : new Set(elegiveis);
        });
    }
    function clearSelection() {
        setSelectedIds(new Set());
    }

    const elegiveisIds = useMemo(
        () => sugadores.filter(s => s.tipo === 'adgroup').map(s => s.id),
        [sugadores],
    );
    const allElegiveisSelected = elegiveisIds.length > 0
        && elegiveisIds.every(id => selectedIds.has(id));
    const selectedCount = selectedIds.size;

    return (
        <AppLayout title={`Sugadores · ${company?.name ?? ''}`}>
            {/* Phase 55-01 (T3) — Blur Fade CSS puro via tailwindcss-animate (0 kb novos).
                Envolve todo o conteúdo do <AppLayout>. NUNCA instalar framer-motion aqui. */}
            <div className="animate-in fade-in duration-300">
            {/* Breadcrumb + voltar ───────────────────────────────────────────*/}
            <div className="mb-4 flex items-center gap-2 text-xs text-white/40">
                <Link href={route('sugadores.index')} className="hover:text-white/70 inline-flex items-center gap-1">
                    <ArrowLeft size={12} />
                    Sugadores
                </Link>
                <span className="text-white/20">/</span>
                <span className="text-white/60 truncate">{company?.name || '—'}</span>
            </div>

            {/* Phase 54-02 (A1) — Layout 2 colunas: main list (col-span-3) + sidebar sticky (col-span-1).
                Mobile (<lg): grid-cols-1 empilha; sidebar sobe naturalmente pelo grid. `min-w-0` na
                coluna esquerda e OBRIGATORIO (research §1) — sem ele a tabela empurra o grid. */}
            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <div className="lg:col-span-3 min-w-0 space-y-4">
                    {/* Header — nome empresa + contagem (SEM ConfigResumoCard, foi pra sidebar) */}
                    <div className="card-ecf rounded-xl p-5">
                        <div className="flex items-start justify-between gap-4 flex-wrap">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 mb-1">
                                    <Building2 size={16} className="text-white/40" />
                                    <h1 className="text-white font-display font-bold text-xl truncate">
                                        {company?.name || '—'}
                                    </h1>
                                </div>
                                <p className="text-white/50 text-sm">
                                    {sugadores.length === 0
                                        ? 'Nenhum sugador pendente ou em ação.'
                                        : `${fmtInt(sugadores.length)} sugador${sugadores.length !== 1 ? 'es' : ''} acionavel${sugadores.length !== 1 ? 'is' : ''}`}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Phase 54-02 (B1) — Filtro de periodo com <select> nativo (research §3/§4).
                        NAO usar Components/ui/select.jsx: classes shadcn (bg-background border-input)
                        quebram dark theme. Presets vem da prop periodo_presets do backend (Wave 1). */}
                    <div className="flex items-center gap-3 flex-wrap">
                        <label htmlFor="periodo-filter" className="text-white/50 text-xs uppercase tracking-wide">
                            Período
                        </label>
                        <select
                            id="periodo-filter"
                            value={periodo}
                            onChange={e => aplicarPeriodo(e.target.value)}
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
                        >
                            {periodo_presets.map(p => (
                                <option key={p.value} value={p.value} className="bg-ecf-card">
                                    {p.label}
                                </option>
                            ))}
                        </select>
                        <span className="text-white/30 text-[11px]">
                            {sugadores.length === 0
                                ? 'Nenhum sugador no período'
                                : `${fmtInt(sugadores.length)} no período`}
                        </span>
                    </div>

                    {/* Barra bulk — aparece quando ha selecao ────────────────────────*/}
            {selectedCount > 0 && (
                <div className="sticky top-2 z-30 mb-3 card-ecf rounded-xl p-3 border-ecf-yellow/40 bg-ecf-yellow/[0.06] flex items-center gap-3 flex-wrap">
                    <span className="text-white/80 text-sm font-medium">
                        {selectedCount} selecionado{selectedCount !== 1 ? 's' : ''}
                    </span>

                    <button
                        type="button"
                        onClick={copiarMlbsBulk}
                        disabled={bulkCopying}
                        className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-70 text-[12px] font-bold"
                        title={`Copia MLBs de todos os ${selectedCount} sugadores selecionados`}
                    >
                        {bulkCopying
                            ? <><Loader2 size={12} className="animate-spin" /> Copiando...</>
                            : bulkFeedback?.ok
                                ? <><Check size={12} /> Copiado {bulkFeedback.total} MLBs</>
                                : <><Copy size={12} /> Copiar MLBs dos selecionados</>}
                    </button>

                    <button
                        type="button"
                        onClick={clearSelection}
                        className="text-white/50 hover:text-white text-[12px] underline-offset-2 hover:underline"
                    >
                        Limpar seleção
                    </button>

                    {bulkFeedback?.error && (
                        <span className="inline-flex items-center gap-1.5 text-red-300 text-[12px]">
                            <AlertTriangle size={12} />
                            {bulkFeedback.error}
                        </span>
                    )}
                    {bulkFeedback?.ok && bulkFeedback.processados != null && (
                        <span className="text-white/50 text-[11px]">
                            ({bulkFeedback.processados} sugadores processados)
                        </span>
                    )}
                </div>
            )}

            {/* Tabela ────────────────────────────────────────────────────────*/}
            {sugadores.length === 0 ? (
                <div className="card-ecf rounded-xl p-12 text-center">
                    <Building2 size={32} className="mx-auto text-white/20 mb-3" />
                    <p className="text-white/60 text-sm font-medium">Nenhum sugador nesta empresa.</p>
                    <p className="text-white/30 text-xs mt-1">
                        {can_analyze
                            ? 'Rode uma análise no card ao lado para detectar novos sugadores.'
                            : 'Aguarde a próxima análise programada.'}
                    </p>
                </div>
            ) : (
                // Phase 55-01 (T2) — Wrapper shadcn <Table>. Overrides dark theme aplicados
                // NO CONSUMIDOR (o wrapper compartilhado Components/ui/table.jsx nao muda,
                // pois e usado por 9 paginas com paleta light-adjacent). Ref research §5.
                <div className="card-ecf rounded-xl overflow-hidden">
                    <Table>
                        <TableHeader className="border-white/[0.06] bg-white/[0.02]">
                            {/* hover:bg-transparent NEUTRALIZA o hover:bg-muted/50 default do wrapper
                                (que ficaria feio no header dark). */}
                            <TableRow className="hover:bg-transparent border-white/[0.06]">
                                <TableHead className="w-10 text-white/50 font-medium text-xs uppercase tracking-wider">
                                    <Checkbox
                                        checked={allElegiveisSelected}
                                        onCheckedChange={toggleAllVisible}
                                        disabled={elegiveisIds.length === 0}
                                        aria-label="Selecionar todos os adgroups desta empresa"
                                    />
                                </TableHead>
                                <TableHead className="text-white/50 font-medium text-xs uppercase tracking-wider">Produto</TableHead>
                                <TableHead className="text-white/50 font-medium text-xs uppercase tracking-wider">Status</TableHead>
                                <TableHead className="text-white/50 font-medium text-xs uppercase tracking-wider">Detectado</TableHead>
                                <TableHead className="text-right text-white/50 font-medium text-xs uppercase tracking-wider">Ações</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sugadores.map(s => {
                                const TipoIcon = TIPO_ICONS[s.tipo] || Tag;
                                const isSelected = selectedIds.has(s.id);
                                const isElegivel = s.tipo === 'adgroup';
                                const fb = copiedFeedback[s.id];
                                return (
                                    // Phase 54-02 (B2) preservado — row inteira clicavel navega pro Show.
                                    // data-state={isSelected ? 'selected' : undefined} alimenta o override CSS
                                    // data-[state=selected]:bg-ecf-yellow/[0.03] (semantica shadcn oficial).
                                    <TableRow
                                        key={s.id}
                                        data-state={isSelected ? 'selected' : undefined}
                                        onClick={() => router.visit(route('sugadores.show', s.id))}
                                        className={cn(
                                            'border-white/[0.05] hover:bg-white/[0.03] cursor-pointer transition-colors',
                                            'data-[state=selected]:bg-ecf-yellow/[0.03]',
                                        )}
                                    >
                                        {/* stopPropagation no <TableCell> — clicar no checkbox nao navega.
                                            Radix Checkbox dispara onCheckedChange (nao onChange). */}
                                        <TableCell onClick={e => e.stopPropagation()} className="w-10 py-3">
                                            <Checkbox
                                                checked={isSelected}
                                                onCheckedChange={() => toggleOne(s.id)}
                                                disabled={!isElegivel}
                                                aria-label={isElegivel ? 'Selecionar sugador' : 'Sugadores de campanha não entram no bulk copy MLBs'}
                                            />
                                        </TableCell>
                                        <TableCell className="min-w-0 py-3">
                                            <div className="flex items-center gap-2 min-w-0">
                                                <TipoIcon size={12} className="text-white/40 shrink-0" />
                                                <span className="text-white/90 text-sm truncate" title={s.adgroup_name || s.campaign_name}>
                                                    {s.adgroup_name || s.campaign_name || '—'}
                                                </span>
                                            </div>
                                            <p className="text-white/30 text-[11px] mt-0.5">
                                                {TIPO_LABELS[s.tipo] || s.tipo}
                                                {' · '}
                                                Invest. {fmtBRL(s.investimento_periodo)}
                                            </p>
                                        </TableCell>
                                        <TableCell className="py-3">
                                            <StatusBadge status={s.status} />
                                        </TableCell>
                                        <TableCell className="py-3 text-white/60 text-xs whitespace-nowrap">
                                            {fmtDate(s.reference_date)}
                                            {s.days_since_detected > 0 && (
                                                <span className="text-white/30 text-[10px] block">
                                                    há {s.days_since_detected} dia{s.days_since_detected !== 1 ? 's' : ''}
                                                </span>
                                            )}
                                        </TableCell>
                                        {/* stopPropagation preservado — botao "MLBs" inline nao dispara navegacao.
                                            Dropdown de acoes "⋯" fica para Wave 2 (55-02). */}
                                        <TableCell onClick={e => e.stopPropagation()} className="py-3 text-right min-w-[80px]">
                                            <div className="inline-flex items-center gap-1.5 justify-end flex-wrap">
                                                {isElegivel && (
                                                    <button
                                                        type="button"
                                                        onClick={() => copiarMlbsLinha(s.id)}
                                                        disabled={copyingId === s.id}
                                                        className="inline-flex items-center gap-1.5 h-7 px-2 rounded-md border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] disabled:opacity-50 text-[11px]"
                                                        title="Copiar MLBs deste adgroup"
                                                    >
                                                        {copyingId === s.id
                                                            ? <><Loader2 size={11} className="animate-spin" /> ...</>
                                                            : fb?.ok
                                                                ? <><Check size={11} className="text-emerald-300" /> {fb.total}</>
                                                                : fb?.error
                                                                    ? <span className="text-red-300">{fb.error}</span>
                                                                    : <><Copy size={11} /> MLBs</>}
                                                    </button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}
                </div>

                {/* Phase 54-02 (A1) — Sidebar sticky com ConfigResumoCard.
                    lg:sticky lg:top-4 lg:self-start = mantem o card visivel ao rolar a tabela.
                    Em mobile (grid-cols-1) a aside cai naturalmente pra cima da lista. */}
                <aside className="lg:col-span-1 lg:sticky lg:top-4 lg:self-start space-y-4">
                    <ConfigResumoCard
                        config={sugador_config}
                        companyId={company?.id}
                        canManage={can_manage_config}
                        canAnalyze={can_analyze}
                        analyzing={analyzing}
                        elapsed={elapsed}
                        onRodarAnalise={rodarAnalise}
                    />
                </aside>
            </div>
            </div>
        </AppLayout>
    );
}
