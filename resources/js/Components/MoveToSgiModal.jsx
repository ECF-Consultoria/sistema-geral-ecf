import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { X, Loader2, AlertTriangle, ArrowRightLeft, ListTree } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Modal compartilhado entre Show (individual) e Index (bulk) para marcar
 * sugador(es) como "movido para campanha SGI X". Não chama API do ML —
 * apenas registra a decisão; o move físico é feito pelo analista no painel.
 *
 * Props:
 *   - company:        { id, name } — empresa-fonte para listar SGI candidatas
 *   - sugadorIds:     number[] — quando length=1 usa endpoint /move; senão /bulk-move
 *   - countLabel:     string — texto opcional pra header (ex: "5 selecionados")
 *   - onClose:        () => void
 *   - onSuccess:      () => void — chamado após sucesso do submit
 */
export default function MoveToSgiModal({ company, sugadorIds, countLabel, onClose, onSuccess }) {
    const [state, setState] = useState({ loading: true, error: null, campaigns: [] });
    const [campaignId, setCampaignId] = useState('');
    const [observacao, setObservacao] = useState('');
    const [submitting, setSubmitting] = useState(false);

    // Busca lista on mount — endpoint cacheia 10min server-side, então abrir/fechar
    // o modal várias vezes não martela a Adman.
    useEffect(() => {
        let alive = true;
        const fetchCampaigns = async () => {
            try {
                const res = await fetch(route('sugadores.sgi-campaigns', company.id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const body = await res.json().catch(() => null);
                if (!alive) return;
                if (!res.ok) {
                    setState({ loading: false, error: body?.reason || `HTTP ${res.status}`, campaigns: [] });
                    return;
                }
                setState({ loading: false, error: null, campaigns: body?.campaigns ?? [] });
            } catch (e) {
                if (alive) setState({ loading: false, error: e.message || 'Falha ao carregar', campaigns: [] });
            }
        };
        fetchCampaigns();
        return () => { alive = false; };
    }, [company.id]);

    const selected = state.campaigns.find(c => c.id === campaignId);

    function submit(e) {
        e.preventDefault();
        if (!selected) return;

        setSubmitting(true);
        const isBulk = sugadorIds.length > 1;

        const payload = isBulk
            ? {
                sugador_ids:           sugadorIds,
                campanha_destino_id:   selected.id,
                campanha_destino_nome: selected.name,
            }
            : {
                campanha_destino_id:   selected.id,
                campanha_destino_nome: selected.name,
                observacao:            observacao || null,
            };

        const url = isBulk
            ? route('sugadores.bulk-move')
            : route('sugadores.move', sugadorIds[0]);

        router.post(url, payload, {
            preserveScroll: true,
            onSuccess: () => { onSuccess?.(); onClose?.(); },
            onFinish: () => setSubmitting(false),
        });
    }

    const count = sugadorIds.length;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-lg p-6">
                <div className="flex items-start justify-between mb-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                            <ArrowRightLeft size={16} className="text-ecf-yellow" />
                            <h3 className="text-white font-display font-bold text-lg">Mover para campanha SGI</h3>
                        </div>
                        <p className="text-white/50 text-xs">
                            {company.name}
                            {countLabel
                                ? <span className="text-ecf-yellow font-semibold"> · {countLabel}</span>
                                : count > 1 && <span className="text-ecf-yellow font-semibold"> · {count} sugadores</span>}
                        </p>
                    </div>
                    <button onClick={onClose} className="text-white/40 hover:text-white shrink-0">
                        <X size={18} />
                    </button>
                </div>

                <p className="text-white/50 text-[11px] mb-4 leading-relaxed">
                    O sistema registra essa decisão como audit log. <b className="text-white/70">O move real
                    no Mercado Livre Ads continua sendo feito por você</b> — esta tela não
                    chama a API do ML, apenas marca o sugador como tratado.
                </p>

                {state.loading && (
                    <div className="flex items-center gap-2 text-white/50 text-sm py-6 justify-center">
                        <Loader2 size={14} className="animate-spin" />
                        Carregando campanhas SGI desta empresa...
                    </div>
                )}

                {state.error && (
                    <div className="flex items-start gap-2 p-3 rounded-lg border border-red-500/20 bg-red-500/[0.05] mb-4">
                        <AlertTriangle size={14} className="text-red-400 shrink-0 mt-0.5" />
                        <div>
                            <p className="text-red-300 text-xs leading-relaxed">{state.error}</p>
                            <p className="text-red-300/60 text-[10px] mt-1">
                                Verifique se a empresa tem campanha cujo nome contenha <code>SGI</code>/<code>Sugador</code>/<code>Sugadores</code> e que esteja pausada.
                            </p>
                        </div>
                    </div>
                )}

                {!state.loading && !state.error && state.campaigns.length === 0 && (
                    <div className="flex items-start gap-2 p-3 rounded-lg border border-amber-500/20 bg-amber-500/[0.05] mb-4">
                        <ListTree size={14} className="text-amber-400 shrink-0 mt-0.5" />
                        <p className="text-amber-200 text-xs leading-relaxed">
                            Nenhuma campanha SGI/Sugador encontrada nesta empresa.
                            Crie uma campanha de quarentena pausada no ML Ads (com SGI no nome) e clique novamente.
                        </p>
                    </div>
                )}

                {!state.loading && !state.error && state.campaigns.length > 0 && (
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">
                                Campanha destino ({state.campaigns.length} disponível{state.campaigns.length !== 1 ? 'is' : ''})
                            </label>
                            <select
                                value={campaignId}
                                onChange={e => setCampaignId(e.target.value)}
                                required
                                className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                            >
                                <option value="">— selecione —</option>
                                {state.campaigns.map(c => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}{c.status ? ` · ${c.status}` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {count === 1 && (
                            <div>
                                <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">
                                    Observação (opcional)
                                </label>
                                <textarea
                                    value={observacao}
                                    onChange={e => setObservacao(e.target.value)}
                                    rows={2}
                                    placeholder="Ex: cliente solicitou parar veiculação"
                                    className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                                />
                            </div>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <button
                                type="button"
                                onClick={onClose}
                                className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={!campaignId || submitting}
                                className={cn(
                                    'px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold inline-flex items-center gap-1.5'
                                )}
                            >
                                {submitting && <Loader2 size={12} className="animate-spin" />}
                                {submitting
                                    ? 'Movendo...'
                                    : count > 1
                                        ? `Mover ${count} sugadores`
                                        : 'Confirmar move'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
