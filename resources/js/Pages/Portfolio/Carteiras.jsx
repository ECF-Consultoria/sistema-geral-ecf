import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { Briefcase, ChevronRight } from 'lucide-react';
import { formatCurrency, formatPercent, cn } from '@/lib/utils';
import { SourceBadge } from '@/Components/ui/source-badge';

// Labels da taxonomia nova (cargo no setor Performance via pivot user_setores).
// Mesmo mapeamento usado no Dashboard Admin antes do quick 260610-lj6.
const tipoLabel = { analista: 'Analista', estrategista: 'Estrategista' };

const PERIOD_OPTIONS = [
    { value: '1', label: 'Último dia' },
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

// Fase 90 (CART-07) — rótulos pt-BR nunca slug cru (regra sistêmica do
// projeto). Mesma constante conceitual do AdminCarteira.jsx (SETOR_LABELS),
// duplicada aqui de propósito — sem módulo compartilhado pra só 2 usos.
const CONTEXTO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'performance', label: 'Mercado Livre' },
    { value: 'shopee', label: 'Shopee' },
];

// Visão consolidada de carteiras — renderizada na aba Carteira quando o user
// logado é admin (bifurcação em PortfolioController::own). Cards de TODOS
// analistas e estrategistas + métricas agregadas (TACOS, faturamento,
// margem média, gasto em ads) da carteira de cada um no período.
export default function PortfolioCarteiras({
    user_portfolios = [],
    period = '30',
    contexto = 'todos',
    totais = null,
}) {
    const applyPeriod = (value) => {
        router.get(route('portfolio.own'), { period: value, contexto }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const applyContexto = (value) => {
        router.get(route('portfolio.own'), { period, contexto: value }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout title="Carteiras">
            <div className="space-y-6 max-w-[1400px]">

                {/* Header + filtro de período */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <Briefcase size={18} className="text-ecf-yellow/70" />
                        <div>
                            <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">Carteiras</p>
                            <p className="text-white font-display font-extrabold text-lg tracking-tight">Portfólio por Profissional</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <select
                            value={contexto}
                            onChange={e => applyContexto(e.target.value)}
                            title="Filtrar por contexto de serviço"
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
                        >
                            {CONTEXTO_OPTIONS.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                        <select
                            value={period}
                            onChange={e => applyPeriod(e.target.value)}
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
                        >
                            {PERIOD_OPTIONS.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Banner agregado do topo (CART-07/SC4) — soma vem PRONTA do
                    backend (uniao de company_id, nunca soma ingenua entre cards).
                    Renderizacao defensiva: so aparece quando o backend manda `totais`. */}
                {totais && (
                    <div className="card-ecf rounded-xl px-4 py-2.5 text-white/50 text-xs">
                        <span className="text-white font-semibold">{totais.empresas_unicas}</span> empresa{totais.empresas_unicas !== 1 ? 's' : ''} única{totais.empresas_unicas !== 1 ? 's' : ''}
                        {' · '}
                        <span className="text-white font-semibold">{totais.vinculos_servico}</span> vínculo{totais.vinculos_servico !== 1 ? 's' : ''} de serviço
                    </div>
                )}

                {/* Empty state defensivo — improvável em prod com analistas cadastrados */}
                {user_portfolios.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-10 flex flex-col items-center justify-center text-center">
                        <Briefcase size={28} className="text-white/20 mb-3" />
                        {contexto !== 'todos' ? (
                            <>
                                <p className="text-white/60 text-sm font-semibold">Nenhum profissional com vínculos neste contexto.</p>
                                <p className="text-white/30 text-xs mt-1">Troque o filtro para Todos.</p>
                            </>
                        ) : (
                            <>
                                <p className="text-white/60 text-sm font-semibold">Nenhum profissional com carteira.</p>
                                <p className="text-white/30 text-xs mt-1">
                                    Configure analistas/estrategistas no setor Performance para visualizar.
                                </p>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="card-ecf rounded-2xl overflow-hidden">
                        <div className="p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            {user_portfolios.map(u => (
                                <div
                                    key={`${u.tipo}-${u.id}`}
                                    className={cn(
                                        'rounded-xl border border-white/[0.07] bg-white/[0.02] p-4',
                                        'flex flex-col gap-3 hover:border-ecf-yellow/20 transition-colors'
                                    )}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="min-w-0">
                                            <p className="text-white font-semibold text-[13px] truncate">{u.name}</p>
                                            <p className="text-white/30 text-[11px] mt-0.5">
                                                {tipoLabel[u.tipo] ?? u.tipo} · {u.empresas_unicas} empresa{u.empresas_unicas !== 1 ? 's' : ''} · {u.vinculos_servico} vínculo{u.vinculos_servico !== 1 ? 's' : ''}
                                            </p>
                                            {/* Chip âmbar (CART-07/SC4) — mesmas classes do chip da tabela
                                                individual (AdminCarteira.jsx). Flag computada aqui dentro do
                                                .map() (pitfall Rollup — memória feedback_rollup_map_scope_bug). */}
                                            {u.vinculos_sem_fonte_financeira > 0 && (
                                                <span className="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-amber-500/10 text-amber-300/80 border-amber-500/20">
                                                    {u.vinculos_sem_fonte_financeira} vínculo{u.vinculos_sem_fonte_financeira !== 1 ? 's' : ''} sem fonte financeira
                                                </span>
                                            )}
                                            {/* Mini-legenda de fontes (Phase 61) — só renderiza quando o backend
                                                enviou source_counts (flag ON). Ordem canônica: ML → Agregado → Adman →
                                                Sem integração. Cada variante só aparece se count > 0. */}
                                            {u.source_counts && (
                                                <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                                    {u.source_counts.ml > 0 && <span className="text-[10px] text-white/50 inline-flex items-center gap-1"><SourceBadge variant="ml" />{u.source_counts.ml}</span>}
                                                    {u.source_counts.unified > 0 && <span className="text-[10px] text-white/50 inline-flex items-center gap-1"><SourceBadge variant="unified" />{u.source_counts.unified}</span>}
                                                    {u.source_counts.adman > 0 && <span className="text-[10px] text-white/50 inline-flex items-center gap-1"><SourceBadge variant="adman" />{u.source_counts.adman}</span>}
                                                    {u.source_counts.none > 0 && <span className="text-[10px] text-white/50 inline-flex items-center gap-1"><SourceBadge variant="none" />{u.source_counts.none}</span>}
                                                </div>
                                            )}
                                        </div>
                                        <button
                                            onClick={() => router.visit(route('portfolio.show', u.id))}
                                            className="flex items-center gap-1 text-ecf-yellow/60 hover:text-ecf-yellow text-[11px] font-semibold transition-colors shrink-0"
                                        >
                                            Ver <ChevronRight size={12} />
                                        </button>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.05] p-2.5">
                                            <p className="text-white/30 text-[10px] mb-0.5">TACOS Médio</p>
                                            <p className="text-ecf-yellow font-display font-bold text-base">
                                                {u.avg_tacos != null ? formatPercent(u.avg_tacos) : '—'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.05] p-2.5">
                                            <p className="text-white/30 text-[10px] mb-0.5">Faturamento</p>
                                            <p className="text-blue-400 font-display font-bold text-base">
                                                {u.total_revenue > 0 ? formatCurrency(u.total_revenue) : '—'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.05] p-2.5">
                                            <p className="text-white/30 text-[10px] mb-0.5">Margem Méd.</p>
                                            <p className="text-emerald-400 font-display font-bold text-base">
                                                {u.avg_margin != null ? formatPercent(u.avg_margin) : '—'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.05] p-2.5">
                                            <p className="text-white/30 text-[10px] mb-0.5">Gasto Ads</p>
                                            <p className="text-orange-400 font-display font-bold text-base">
                                                {u.total_ad_spend > 0 ? formatCurrency(u.total_ad_spend) : '—'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

            </div>
        </AppLayout>
    );
}
