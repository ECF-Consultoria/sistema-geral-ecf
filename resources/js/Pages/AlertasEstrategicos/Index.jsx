import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ChevronLeft, ChevronRight, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import StatsHeader from './components/StatsHeader';
import AlertaCard from './components/AlertaCard';

/**
 * Phase 23 — Página principal /alertas-estrategicos.
 * Caixa de entrada do comercial: 3 KPI cards no topo + filtros + lista paginada.
 */
export default function Index() {
    const {
        signals, companies_map, stats, filters,
        type_labels, severity_labels, severity_colors, erro,
    } = usePage().props;

    const [localFilters, setLocalFilters] = useState({
        severity:   filters?.severity   ?? '',
        event_type: filters?.event_type ?? '',
        acked:      filters?.acked      ?? false,
        page:       filters?.page       ?? 1,
    });

    // Aplica filtro com reset de página e navega via router.get
    const applyFilter = (key, value) => {
        const next = { ...localFilters, [key]: value, page: 1 };
        setLocalFilters(next);
        router.get(route('alertas.index'), cleanParams(next), {
            preserveState: true,
            preserveScroll: false,
        });
    };

    // Navega para página específica preservando filtros atuais
    const goToPage = (page) => {
        const next = { ...localFilters, page };
        setLocalFilters(next);
        router.get(route('alertas.index'), cleanParams(next), {
            preserveState: true,
            preserveScroll: false,
        });
    };

    // Limpa todos os filtros e volta para o estado inicial
    const limparFiltros = () => {
        const reset = { severity: '', event_type: '', acked: false, page: 1 };
        setLocalFilters(reset);
        router.get(route('alertas.index'), {}, {
            preserveState: false,
            preserveScroll: false,
        });
    };

    const total        = signals?.total ?? 0;
    const page         = signals?.page ?? 1;
    const limit        = signals?.limit ?? 50;
    const totalPaginas = Math.max(1, Math.ceil(total / limit));
    const data         = signals?.data ?? [];

    return (
        <AppLayout title="Alertas Estratégicos">
            <Head title="Alertas Estratégicos" />

            <div className="px-6 py-6 max-w-7xl mx-auto space-y-6">
                {/* Header da página */}
                <div className="flex items-start gap-3">
                    <div className="p-2 rounded-lg bg-red-500/10 border border-red-500/20">
                        <AlertTriangle className="text-red-400" size={22} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold text-white">Alertas Estratégicos</h1>
                        <p className="text-sm text-white/50 mt-0.5">
                            Caixa de entrada do comercial — alertas detectados pela API ECF Drive.
                        </p>
                    </div>
                </div>

                {/* Banner de erro (ECF Drive offline) */}
                {erro && (
                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        {erro}
                    </div>
                )}

                {/* Stats KPI cards */}
                <StatsHeader stats={stats} />

                {/* Linha de filtros */}
                <div className="flex flex-wrap items-end gap-3 p-4 rounded-lg bg-ecf-card border border-white/[0.06]">
                    {/* Filtro: Severidade */}
                    <div className="flex-1 min-w-[160px]">
                        <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1.5">
                            Severidade
                        </label>
                        <select
                            value={localFilters.severity}
                            onChange={e => applyFilter('severity', e.target.value)}
                            className="w-full bg-ecf-bg border border-white/[0.08] rounded-md px-3 py-2 text-sm text-white/90 focus:outline-none focus:border-ecf-yellow/40"
                        >
                            <option value="">Todas</option>
                            {Object.entries(severity_labels ?? {}).map(([k, lbl]) => (
                                <option key={k} value={k}>{lbl}</option>
                            ))}
                        </select>
                    </div>

                    {/* Filtro: Tipo */}
                    <div className="flex-1 min-w-[200px]">
                        <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1.5">
                            Tipo
                        </label>
                        <select
                            value={localFilters.event_type}
                            onChange={e => applyFilter('event_type', e.target.value)}
                            className="w-full bg-ecf-bg border border-white/[0.08] rounded-md px-3 py-2 text-sm text-white/90 focus:outline-none focus:border-ecf-yellow/40"
                        >
                            <option value="">Todos</option>
                            {Object.entries(type_labels ?? {}).map(([k, lbl]) => (
                                <option key={k} value={k}>{lbl}</option>
                            ))}
                        </select>
                    </div>

                    {/* Filtro: Status (visto/não-visto) */}
                    <div className="flex-1 min-w-[160px]">
                        <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1.5">
                            Status
                        </label>
                        <select
                            value={localFilters.acked ? 'true' : 'false'}
                            onChange={e => applyFilter('acked', e.target.value === 'true')}
                            className="w-full bg-ecf-bg border border-white/[0.08] rounded-md px-3 py-2 text-sm text-white/90 focus:outline-none focus:border-ecf-yellow/40"
                        >
                            <option value="false">Não-vistos</option>
                            <option value="true">Já vistos</option>
                        </select>
                    </div>

                    {/* Botão limpar filtros */}
                    <button
                        type="button"
                        onClick={limparFiltros}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs text-white/60 hover:text-white border border-white/[0.08] hover:border-white/20 rounded-md transition-colors"
                    >
                        <X size={14} /> Limpar
                    </button>
                </div>

                {/* Lista de alertas */}
                <div className="space-y-3">
                    {data.length === 0 && !erro && (
                        <div className="rounded-lg border border-white/[0.06] bg-ecf-card px-6 py-12 text-center text-white/50 text-sm">
                            Nenhum alerta encontrado com os filtros atuais.
                        </div>
                    )}

                    {data.map(sinal => (
                        <AlertaCard
                            key={sinal.id}
                            signal={sinal}
                            company={companies_map?.[String(sinal.custId)] ?? null}
                            typeLabels={type_labels}
                            severityLabels={severity_labels}
                            severityColors={severity_colors}
                        />
                    ))}
                </div>

                {/* Paginação */}
                {total > limit && (
                    <div className="flex items-center justify-between pt-4 border-t border-white/[0.06]">
                        <div className="text-xs text-white/40">
                            Mostrando {(page - 1) * limit + 1}–{Math.min(page * limit, total)} de {total}
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => goToPage(page - 1)}
                                className={cn(
                                    'inline-flex items-center gap-1 px-3 py-1.5 text-xs border border-white/[0.08] rounded-md transition-colors',
                                    page <= 1 ? 'text-white/20 cursor-not-allowed' : 'text-white/70 hover:text-white hover:border-white/20',
                                )}
                            >
                                <ChevronLeft size={14} /> Anterior
                            </button>
                            <span className="text-xs text-white/40 px-2">
                                Página {page} de {totalPaginas}
                            </span>
                            <button
                                type="button"
                                disabled={page >= totalPaginas}
                                onClick={() => goToPage(page + 1)}
                                className={cn(
                                    'inline-flex items-center gap-1 px-3 py-1.5 text-xs border border-white/[0.08] rounded-md transition-colors',
                                    page >= totalPaginas ? 'text-white/20 cursor-not-allowed' : 'text-white/70 hover:text-white hover:border-white/20',
                                )}
                            >
                                Próxima <ChevronRight size={14} />
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/**
 * Remove params vazios/falsy desnecessários da URL.
 * Mantém acked=false implícito (default) fora da URL para não poluir.
 */
function cleanParams(params) {
    const out = {};
    for (const [k, v] of Object.entries(params)) {
        if (v === '' || v === null || v === undefined) continue;
        if (k === 'page' && v === 1) continue;
        if (k === 'acked' && v === false) continue; // Default — não polui URL
        out[k] = v;
    }
    return out;
}
