import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { Briefcase, ChevronRight } from 'lucide-react';
import { formatCurrencyCompact, formatPercent, cn } from '@/lib/utils';
import { SourceBadge } from '@/Components/ui/source-badge';

// Labels da taxonomia nova (cargo no setor Performance via pivot user_setores).
// Mesmo mapeamento usado no Dashboard Admin antes do quick 260610-lj6.
const tipoLabel = { analista: 'Analista', estrategista: 'Estrategista' };

// Glow radial por tipo de profissional (identidade da dashboard ML — brilho
// borrado no topo do card que intensifica no hover).
const GLOW_TIPO = {
    analista:     'radial-gradient(circle, rgba(56,189,248,0.55), transparent 70%)',   // sky
    estrategista: 'radial-gradient(circle, rgba(168,85,247,0.55), transparent 70%)',   // roxo
};
const glowDoTipo = (tipo) => GLOW_TIPO[tipo] ?? 'radial-gradient(circle, rgba(255,230,0,0.45), transparent 70%)';

// Mini-KPI com brilho (usado dentro do card do profissional). O `group-hover`
// referencia o card externo (que tem `group`) — passar o mouse no card acende
// os três brilhos juntos.
function MetricMini({ label, value, valueClass, glow }) {
    return (
        <div className="relative overflow-hidden rounded-xl border border-white/[0.06] bg-white/[0.02] p-2.5 min-w-0">
            <div
                className="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 h-16 w-[85%] rounded-full blur-2xl opacity-[0.15] group-hover:opacity-40 transition-opacity duration-300"
                style={{ background: glow }}
            />
            <div className="relative z-10 min-w-0">
                <p className="text-white/35 text-[9.5px] font-semibold uppercase tracking-wide mb-1 leading-tight">{label}</p>
                <p className={cn('font-display font-extrabold text-[15px] tabular-nums truncate', valueClass)}>{value}</p>
            </div>
        </div>
    );
}

// Fase 90 (CART-07) — rótulos pt-BR nunca slug cru (regra sistêmica do
// projeto). Mesma constante conceitual do AdminCarteira.jsx (SETOR_LABELS),
// duplicada aqui de propósito — sem módulo compartilhado pra só 2 usos.
const CONTEXTO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'performance', label: 'Mercado Livre' },
    { value: 'shopee', label: 'Shopee' },
];

// ─── Fase 104 (UIP-01/UIP-03/UIP-04) — toggle de período/contexto de bônus ─
// Substitui o seletor rolante legado (1/7/30/180) — o backend já ignora essa
// janela desde a Fase 103 (o `period` legado só continua sendo ecoado no
// payload, sem alimentar mais o cálculo). Rótulos travados pelo usuário —
// nunca renderizar slug cru ('em_curso'/'bonus_atual'/'closed_period'/
// 'official' na tela). Duplicado de propósito em Performance/Index.jsx e
// AdminCarteira.jsx (mesma convenção de CONTEXTO_OPTIONS duplicada).
const MESES_EXTENSO = [
    'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
    'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
];

// 'YYYY-MM' → "junho/2026"
function formatCompetencia(yyyyMm) {
    if (!yyyyMm) return '—';
    const [y, m] = String(yyyyMm).split('-');
    return `${MESES_EXTENSO[parseInt(m, 10) - 1] ?? '—'}/${y}`;
}

// 'YYYY-MM' → "julho" (sem ano — usado no "pago em {mês}")
function formatMesSolo(yyyyMm) {
    if (!yyyyMm) return '—';
    const [, m] = String(yyyyMm).split('-');
    return MESES_EXTENSO[parseInt(m, 10) - 1] ?? '—';
}

// 'YYYY-MM-DD' → "01/06" (subtítulo discreto de auditoria)
function formatRangeCurto(iso) {
    if (!iso) return '—';
    const [, m, d] = String(iso).split('-');
    return `${d}/${m}`;
}

const PERIODO_SEGMENTOS = [
    { key: 'em_curso', label: 'Em curso' },
    { key: 'bonus_atual', label: 'Bônus atual' },
    // "Mês fechado" removido (2026-07-23) — era redundante com "Bônus atual"
    // (ambos apontam para o último mês fechado / competência de bônus).
];

function PeriodoToggle({ segmentoAtivo, onSelect }) {
    return (
        <div className="flex rounded-xl border border-white/[0.08] overflow-hidden">
            {PERIODO_SEGMENTOS.map((opt, i) => (
                <button
                    key={opt.key}
                    type="button"
                    onClick={() => onSelect(opt.key)}
                    className={cn(
                        'px-3 h-9 text-[13px] font-medium transition-colors',
                        segmentoAtivo === opt.key
                            ? 'bg-ecf-yellow/[0.12] text-ecf-yellow'
                            : 'text-white/50 hover:text-white/80 hover:bg-white/[0.04]',
                        i > 0 && 'border-l border-white/[0.08]',
                    )}
                >
                    {opt.label}
                </button>
            ))}
        </div>
    );
}

// Visão consolidada de carteiras — renderizada na aba Carteira quando o user
// logado é admin (bifurcação em PortfolioController::own). Cards de TODOS
// analistas e estrategistas + métricas agregadas (TACOS, faturamento,
// margem média, gasto em ads) da carteira de cada um no período.
export default function PortfolioCarteiras({
    user_portfolios = [],
    contexto = 'todos',
    totais = null,
    periodo = null,
    bonus = null,
}) {
    // Fase 104 (UIP-01) — segmento ativo lido da URL (?modo=), não de estado
    // local — reflete exatamente o que o backend resolveu.
    const searchParams = new URLSearchParams(window.location.search);
    const modoUrl = searchParams.get('modo');
    // Só dois segmentos: Em curso (default) e Bônus atual (?modo=bonus_atual).
    // "Mês fechado" foi removido (redundante com Bônus atual).
    const segmentoAtivo = modoUrl === 'bonus_atual' ? 'bonus_atual' : 'em_curso';

    // Navegação unificada — preserva ?contexto= e ?modo=, sobrescrevendo só o
    // que o `overrides` pedir. Nunca mais carrega ?mes= (seletor removido).
    const navigate = (overrides) => {
        const base = { contexto, modo: modoUrl ?? undefined };
        const params = new URLSearchParams();
        Object.entries({ ...base, ...overrides }).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') params.set(k, v);
        });
        router.visit(route('portfolio.own') + '?' + params.toString(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const applyContexto = (value) => navigate({ contexto: value });

    // Troca de segmento do toggle: Bônus atual (?modo=bonus_atual) ou Em curso.
    const applyPeriodo = (segmento) => {
        if (segmento === 'bonus_atual') {
            navigate({ modo: 'bonus_atual', mes: undefined });
        } else {
            navigate({ modo: undefined, mes: undefined });
        }
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
                            {/* Subtítulo discreto de auditoria — janela atual vs baseline. */}
                            {periodo?.current_start && periodo?.baseline_start && (
                                <p className="text-white/30 text-[11px] mt-0.5">
                                    {formatRangeCurto(periodo.current_start)}–{formatRangeCurto(periodo.current_end)}
                                    {' vs '}
                                    {formatRangeCurto(periodo.baseline_start)}–{formatRangeCurto(periodo.baseline_end)}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2 flex-wrap">
                        {/* Fase 104 (UIP-01) — toggle de contexto de período, substitui o
                            seletor rolante legado 1/7/30/180 (o backend já ignora essa
                            janela desde a Fase 103). */}
                        <PeriodoToggle segmentoAtivo={segmentoAtivo} onSelect={applyPeriodo} />

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
                    </div>
                </div>

                {/* Fase 104 (UIP-03) — no modo Bônus atual, deixa explícito qual
                    competência está sendo avaliada e quando ela é paga. */}
                {segmentoAtivo === 'bonus_atual' && bonus && (
                    <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] px-4 py-2.5 text-ecf-yellow/90 text-sm font-medium">
                        Competência {formatCompetencia(bonus.competence_month)} · pago em {formatMesSolo(bonus.payment_month)}
                    </div>
                )}
                {/* Indicador operacional vs oficial (UIP-04) — em curso é parcial. */}
                {segmentoAtivo === 'em_curso' && (
                    <div className="rounded-xl border border-amber-500/25 bg-amber-500/[0.05] px-4 py-2.5 text-amber-200/90 text-xs font-medium">
                        Parcial · mês em andamento — a consolidação mensal fecha dia 1 do mês seguinte.
                    </div>
                )}

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
                                        'group relative overflow-hidden rounded-2xl border border-white/[0.08] p-4',
                                        'bg-gradient-to-b from-white/[0.04] to-white/[0.01]',
                                        'flex flex-col gap-3.5 transition-all duration-300',
                                        'hover:border-ecf-yellow/30 hover:from-white/[0.06]',
                                    )}
                                >
                                    {/* Brilho de canto (identidade dashboard ML) — acende no hover. */}
                                    <div
                                        className="pointer-events-none absolute -top-16 -right-12 h-44 w-44 rounded-full blur-3xl opacity-[0.13] group-hover:opacity-30 transition-opacity duration-500"
                                        style={{ background: glowDoTipo(u.tipo) }}
                                    />
                                    <div className="relative z-10 flex items-center justify-between">
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
                                    {/* Só o que é relevante (2026-07-23): Faturamento total,
                                        Margem média % e quantidade de empresas. Formato compacto
                                        no faturamento evita estourar o card em números grandes. */}
                                    <div className="relative z-10 grid grid-cols-3 gap-2">
                                        <MetricMini
                                            label="Faturamento total"
                                            value={u.total_revenue > 0 ? formatCurrencyCompact(u.total_revenue) : '—'}
                                            valueClass="text-blue-300"
                                            glow="radial-gradient(circle, rgba(59,130,246,0.5), transparent 70%)"
                                        />
                                        <MetricMini
                                            label="Margem média"
                                            value={u.avg_margin != null ? formatPercent(u.avg_margin) : '—'}
                                            valueClass="text-emerald-300"
                                            glow="radial-gradient(circle, rgba(52,211,153,0.5), transparent 70%)"
                                        />
                                        <MetricMini
                                            label="Empresas"
                                            value={u.empresas_unicas ?? 0}
                                            valueClass="text-ecf-yellow"
                                            glow="radial-gradient(circle, rgba(255,230,0,0.45), transparent 70%)"
                                        />
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
