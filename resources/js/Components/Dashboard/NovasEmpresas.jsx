import { Link } from '@inertiajs/react';
import { cn, formatCurrency, formatPercent } from '@/lib/utils';

const STATUS_LABELS = {
    'ramp-up': 'Ramp-up',
    atencao: 'Atenção',
    saudavel: 'Saudável',
};

/**
 * NovasEmpresas — card "Novas empresas no mês" (Fase 97 Plan 04, DASH-97-7).
 *
 * Largura total, CONDICIONAL (só renderiza quando há empresas — D3 do
 * CONTEXT: contrato Performance ativo iniciado no mês corrente, injetado
 * pelo DashboardController como `novas_empresas`, Plan 97-02). Cada card
 * linka para `companies.show`.
 */
export default function NovasEmpresas({ empresas = [] }) {
    const lista = Array.isArray(empresas) ? empresas : [];
    if (lista.length === 0) return null;

    return (
        <div className="card-ecf rounded-2xl p-6">
            <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div className="flex items-center gap-2.5">
                    <p className="text-white font-display font-extrabold text-lg tracking-tight">Novas empresas no mês</p>
                    <span className="text-[11px] font-bold rounded-full px-2 py-0.5 bg-white/[0.06] text-white/60">
                        {lista.length}
                    </span>
                </div>
                <Link
                    href={route('companies.index')}
                    className="text-[12px] font-semibold text-blue-400 hover:text-blue-300 transition-colors whitespace-nowrap"
                >
                    Listagem / cadastro →
                </Link>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {lista.map((c) => {
                    // Cor do badge de status computada AQUI, DENTRO do callback
                    // do .map() — pitfall Rollup (Fase 97): nunca herdar de
                    // variável de escopo externo.
                    const statusColors = {
                        'ramp-up': 'bg-blue-500/15 text-blue-400',
                        atencao: 'bg-orange-500/15 text-orange-400',
                        saudavel: 'bg-emerald-500/15 text-emerald-400',
                    };
                    const responsaveis = [c.analista, c.estrategista].filter(Boolean).join(' · ') || '—';

                    return (
                        <Link
                            key={c.id}
                            href={route('companies.show', c.id)}
                            className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5 flex flex-col gap-2.5 hover:border-white/20 transition-colors"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-white/85 text-[13px] font-bold truncate">{c.name}</p>
                                    <p className="text-white/30 text-[11px] truncate">{c.grupo ?? '—'}</p>
                                </div>
                                <span
                                    className={cn(
                                        'shrink-0 text-[10px] font-bold rounded-md px-2 py-0.5 whitespace-nowrap',
                                        statusColors[c.status] ?? 'bg-white/[0.06] text-white/50',
                                    )}
                                >
                                    {STATUS_LABELS[c.status] ?? c.status}
                                </span>
                            </div>
                            <div className="flex gap-4">
                                <div>
                                    <p className="text-white/30 text-[10px] font-semibold uppercase tracking-wide">Fat. parcial</p>
                                    <p className="text-white/85 text-[14px] font-bold tabular-nums">{formatCurrency(c.faturamento_parcial)}</p>
                                </div>
                                <div>
                                    <p className="text-white/30 text-[10px] font-semibold uppercase tracking-wide">TACoS</p>
                                    <p className="text-white/85 text-[14px] font-bold tabular-nums">{c.tacos != null ? formatPercent(c.tacos) : '—'}</p>
                                </div>
                            </div>
                            <p className="text-white/40 text-[11px] truncate">{responsaveis}</p>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
