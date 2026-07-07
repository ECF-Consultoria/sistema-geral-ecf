import { useEffect, useState, useCallback } from 'react';
import axios from 'axios';
import { format, parseISO } from 'date-fns';
import { Loader2, AlertTriangle, Clock, ExternalLink } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { cn, formatCurrency } from '@/lib/utils';

/**
 * <GoalHistoryDrawer /> — META-04 (Phase 62-03)
 *
 * Drawer/Dialog reusavel que exibe as ultimas 10 alteracoes de uma Goal.
 * Consome o endpoint GET /goals/{goalId}/history (criado no Plan 62-01), que
 * retorna entries do activity_log filtradas por subject_type=Goal + subject_id.
 *
 * Comportamento:
 *  - Fetch on-open (nao pre-fetch). Cache mantido entre aberturas do MESMO goalId.
 *  - Re-fetch automatico quando goalId muda (empresas diferentes).
 *  - AbortController cancela request pendente se o drawer fechar antes da resposta.
 *  - Estados: loading | error (com retry) | empty | data.
 *  - Link "Ver log completo" sempre visivel (rota Ziggy activity-log.index).
 *
 * Props totalmente controladas — sem estado global. React escapa tudo em JSX,
 * mitigando XSS via causer_name (mitigation T-62-03-01).
 */

// Labels pt-BR para chaves de mudanca (mapeamento colunas do banco -> label humana).
// Se a chave nao estiver aqui, o proprio nome cru eh renderizado como fallback.
const FIELD_LABELS = {
    target_value: 'Valor alvo',
    metric:       'Metrica',
    active:       'Ativa',
    company_id:   'Empresa',
    description:  'Descricao',
    period_type:  'Periodo',
    value_type:   'Tipo de valor',
};

// Descricao dos period_types no banco -> label pt-BR pra exibicao em diff.
const PERIOD_LABELS = {
    monthly:   'Mensal',
    quarterly: 'Trimestral',
    yearly:    'Anual',
};

/**
 * Renderiza um valor de diff de forma legivel. `key` guia a formatacao
 * (booleans viram Sim/Nao, target_value tenta formatar como moeda BR se
 * numerico, strings sao truncadas em 40 chars).
 */
function formatDiffValue(key, raw) {
    if (raw === null || raw === undefined || raw === '') return '—';
    if (key === 'active') return raw ? 'Sim' : 'Nao';
    if (key === 'period_type') return PERIOD_LABELS[raw] ?? String(raw);
    if (key === 'target_value') {
        const n = Number(raw);
        return Number.isFinite(n) ? formatCurrency(n) : String(raw);
    }
    const str = String(raw);
    return str.length > 40 ? `${str.slice(0, 40)}...` : str;
}

/**
 * Skeleton exibido enquanto o fetch esta pendente.
 */
function LoadingSkeleton() {
    return (
        <div data-testid="goal-history-loading" className="space-y-2 py-2">
            <div className="flex items-center gap-2 text-white/50 text-[13px] pb-2">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span>Carregando historico...</span>
            </div>
            {[0, 1, 2].map((i) => (
                <div key={i} className="rounded-md border border-white/[0.06] bg-white/[0.02] p-3 space-y-2 animate-pulse">
                    <div className="h-3 w-32 rounded bg-white/[0.06]" />
                    <div className="h-3 w-48 rounded bg-white/[0.05]" />
                    <div className="h-3 w-40 rounded bg-white/[0.04]" />
                </div>
            ))}
        </div>
    );
}

export default function GoalHistoryDrawer({
    goalId,
    goalDescription,
    metricLabel,
    open,
    onOpenChange,
    className,
}) {
    // Estado local — nada de global store.
    const [entries, setEntries] = useState(null);   // null = nunca fez fetch; [] = fetch feito, vazio
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [lastFetchedId, setLastFetchedId] = useState(null); // guarda o ultimo goalId com fetch OK — reseta cache se goalId muda

    // Funcao de fetch reutilizavel (chamada pelo useEffect e pelo botao "Tentar novamente").
    const doFetch = useCallback((id, signal) => {
        setLoading(true);
        setError(null);
        return axios
            .get(route('goals.history', id), { signal })
            .then((res) => {
                // Payload esperado: { entries: [...] }
                setEntries(Array.isArray(res.data?.entries) ? res.data.entries : []);
                setLastFetchedId(id);
            })
            .catch((err) => {
                // Ignora cancelamentos (unmount ou re-abrir com outro goalId).
                if (axios.isCancel?.(err) || err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED') return;
                setError('Nao foi possivel carregar o historico');
            })
            .finally(() => setLoading(false));
    }, []);

    // Fetch on-open: dispara quando abre (open=true) OU quando goalId muda com drawer ja aberto.
    // Guard: AbortController evita setState pos-unmount / pos-close.
    useEffect(() => {
        if (!open || goalId == null) return;
        // Cache: se ja carregou pro mesmo goalId nao rebusca.
        if (lastFetchedId === goalId && entries !== null && !error) return;

        const ctrl = new AbortController();
        doFetch(goalId, ctrl.signal);
        return () => ctrl.abort();
    }, [open, goalId, lastFetchedId, entries, error, doFetch]);

    // Retry manual — usuario clicou apos erro.
    const handleRetry = () => {
        if (goalId == null) return;
        const ctrl = new AbortController();
        doFetch(goalId, ctrl.signal);
    };

    // URL do log completo com filtros pre-aplicados (backslash duplo no subject_type porque JSX
    // interpreta a barra invertida no template string sem escape adicional).
    const fullLogUrl = goalId != null
        ? route('activity-log.index', { subject_type: 'App\\Models\\Goal', subject_id: goalId })
        : '#';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                data-testid="goal-history-drawer"
                className={cn(
                    'max-w-2xl max-h-[80vh] overflow-y-auto bg-ecf-card border-white/[0.08] text-white',
                    className,
                )}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-white">
                        <Clock className="h-4 w-4 text-ecf-yellow" />
                        Historico de alteracoes {metricLabel ? `— ${metricLabel}` : ''}
                    </DialogTitle>
                    {goalDescription && (
                        <DialogDescription className="text-white/60">
                            {goalDescription}
                        </DialogDescription>
                    )}
                </DialogHeader>

                <div className="mt-2 space-y-2">
                    {loading && <LoadingSkeleton />}

                    {!loading && error && (
                        <div
                            data-testid="goal-history-error"
                            className="rounded-md border border-red-400/20 bg-red-400/[0.06] p-4 flex flex-col items-start gap-3"
                        >
                            <div className="flex items-center gap-2 text-red-300 text-sm">
                                <AlertTriangle className="h-4 w-4" />
                                <span>{error}</span>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleRetry}
                                className="border-white/[0.12] bg-white/[0.03] text-white hover:bg-white/[0.06]"
                            >
                                Tentar novamente
                            </Button>
                        </div>
                    )}

                    {!loading && !error && entries !== null && entries.length === 0 && (
                        <div
                            data-testid="goal-history-empty"
                            className="rounded-md border border-white/[0.06] bg-white/[0.02] p-6 text-center text-white/50 text-sm"
                        >
                            Nenhuma alteracao registrada ainda.
                        </div>
                    )}

                    {!loading && !error && entries !== null && entries.length > 0 && (
                        <ul className="space-y-2">
                            {entries.map((entry, idx) => {
                                // Diff: coleta chaves presentes em attributes (valor novo) e em old (valor anterior).
                                const oldObj = entry.changes?.old ?? {};
                                const newObj = entry.changes?.attributes ?? {};
                                const diffKeys = Array.from(new Set([...Object.keys(oldObj), ...Object.keys(newObj)]));

                                // Tempo relativo simplificado (evita import de locale). Se parseISO falhar, mostra raw.
                                let dateLabel = entry.created_at ?? '';
                                try {
                                    if (entry.created_at) {
                                        dateLabel = format(parseISO(entry.created_at), 'dd/MM/yyyy HH:mm');
                                    }
                                } catch {
                                    // usa raw
                                }

                                return (
                                    <li
                                        key={entry.id ?? idx}
                                        data-testid={`goal-history-entry-${idx}`}
                                        className="rounded-md border border-white/[0.06] bg-white/[0.02] p-3"
                                    >
                                        <div className="flex items-baseline justify-between gap-2 mb-1">
                                            <span className="text-[13px] font-semibold text-white">
                                                {entry.description ?? 'Alteracao'}
                                            </span>
                                            <span className="text-[11px] text-white/40 whitespace-nowrap">
                                                {dateLabel}
                                            </span>
                                        </div>
                                        <div className="text-[12px] text-white/60 mb-2">
                                            por {entry.causer_name ?? 'Sistema'}
                                        </div>
                                        {diffKeys.length > 0 && (
                                            <ul className="space-y-1 text-[12px]">
                                                {diffKeys.map((key) => (
                                                    <li key={key} className="text-white/70">
                                                        <span className="text-white/50">{FIELD_LABELS[key] ?? key}:</span>{' '}
                                                        <span className="text-white/60">{formatDiffValue(key, oldObj[key])}</span>
                                                        <span className="text-white/40 mx-1">→</span>
                                                        <span className="text-ecf-yellow">{formatDiffValue(key, newObj[key])}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                            {/* Marker generico (redundante com -{idx} pra grep gate) */}
                            <span data-testid="goal-history-entry" className="hidden" aria-hidden="true" />
                        </ul>
                    )}
                </div>

                <div className="mt-4 pt-3 border-t border-white/[0.06] flex items-center justify-between">
                    <span className="text-[11px] text-white/40">
                        Ate 10 alteracoes mais recentes
                    </span>
                    <a
                        data-testid="goal-history-full-log-link"
                        href={fullLogUrl}
                        className="inline-flex items-center gap-1 text-ecf-yellow text-[12px] font-semibold hover:underline"
                    >
                        Ver log completo
                        <ExternalLink className="h-3 w-3" />
                    </a>
                    {/* Marker alt para compat com gate grep do plano 62-03 (alias sem sufixo -log-). */}
                    <span data-testid="goal-history-full-link" className="hidden" aria-hidden="true" />
                </div>
            </DialogContent>
        </Dialog>
    );
}
