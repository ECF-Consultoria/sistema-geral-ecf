import { Head, Link, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    Star, TrendingUp, TrendingDown, Coins, UserX, Trophy, Calendar,
    Sparkles, Info, BookOpen,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
// Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS na carteira.
import NpsPendingWidget from '@/Components/Nps/NpsPendingWidget';

/**
 * Dashboard Performance da Carteira — analistas/estrategistas.
 *
 * Phase 74 D-18/D-19 · Wave 4 · Plans 74-06:
 * Reescrito do zero para consumir o shape v2 do DesempenhoScoreService — 4
 * parâmetros (NPS/Faturamento/Margem/Absenteísmo) + faixa de bônus, com
 * placeholder "Em breve" no Absenteísmo (DESEMP-06) e toggle mês fechado /
 * mês em curso (parcial) / diário (D-18). O ranking global em /performance
 * mantém filtro sem_carteira (DESEMP-10).
 *
 * Payload esperado (Plan 74-04 controller):
 *   pessoa, periodo, desempenho (shape v2 do compute()), kpis (derivados
 *   legados p/ backward compat + faixa/nota), nps, metas, empresas,
 *   nps_pendentes, mes_atual, mes_fechado?, resultado_mes_fechado?,
 *   serie_diaria?
 *
 * Design tokens dark/glass — padrão redesign Nps/Index.jsx (2026-07-08).
 */

// ═══ Helpers pt-BR ═══════════════════════════════════════════════════════

const fmtBRLCompact = (n) => {
    if (n == null) return '—';
    if (Math.abs(n) >= 1_000_000) return `R$ ${(n / 1_000_000).toFixed(2)}M`;
    if (Math.abs(n) >= 1_000) return `R$ ${(n / 1_000).toFixed(0)}K`;
    return `R$ ${n.toLocaleString('pt-BR')}`;
};

// % com sinal + 1 casa; null → "—".
function formatPercent(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    const n = Number(v);
    const sinal = n >= 0 ? '+' : '';
    return `${sinal}${n.toFixed(1)}%`;
}

// Mapeia slug de faixa → label pt-BR.
const FAIXA_LABEL = {
    sem_bonus:     'Sem bônus',
    basico:        'Básico',
    intermediario: 'Intermediário',
    maximo:        'Máximo',
};
function faixaLabel(slug) {
    if (!slug) return 'Sem classificação';
    return FAIXA_LABEL[slug] ?? slug;
}

// Cor da faixa (badge/destaque).
const FAIXA_COR = {
    sem_bonus:     { bg: 'bg-white/[0.04]',      border: 'border-white/[0.08]',       text: 'text-white/60' },
    basico:        { bg: 'bg-sky-500/10',        border: 'border-sky-500/30',         text: 'text-sky-300' },
    intermediario: { bg: 'bg-violet-500/10',     border: 'border-violet-500/30',      text: 'text-violet-300' },
    maximo:        { bg: 'bg-ecf-yellow/10',     border: 'border-ecf-yellow/40',      text: 'text-ecf-yellow' },
};
function corFaixa(slug) {
    return FAIXA_COR[slug] ?? FAIXA_COR.sem_bonus;
}

function mesExtenso(iso) {
    if (!iso) return '—';
    try {
        // Constrói a data como "YYYY-MM-01T12:00:00" pra evitar shift TZ.
        const [y, m] = String(iso).split('-');
        const d = new Date(Number(y), Number(m) - 1, 1);
        return d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    } catch {
        return String(iso);
    }
}

// NpsPending guard defensivo.
function safeArray(v) {
    return Array.isArray(v) ? v : [];
}

// ═══ Sub-componentes ══════════════════════════════════════════════════════

/**
 * Card de KPI de parâmetro individual (NPS, Faturamento, Margem, Absenteísmo).
 * Cada parâmetro tem uma cor de acento (yellow/emerald/blue/amber). O card
 * Absenteísmo é o único com badge "Em breve" (DESEMP-06).
 */
function ParametroCard({
    icone: Icone,
    titulo,
    valor,
    sublabel,
    accentColor = 'ecf-yellow',
    emBreve = false,
    trendDir,   // 'up' | 'down' | null
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-ecf-card border border-white/[0.08] p-6 min-h-[168px] flex flex-col">
            {/* Glow decorativo */}
            <div
                className={cn(
                    'absolute -top-16 -right-16 w-40 h-40 rounded-full blur-3xl pointer-events-none opacity-40',
                    accentColor === 'ecf-yellow' && 'bg-ecf-yellow/20',
                    accentColor === 'emerald'    && 'bg-emerald-500/20',
                    accentColor === 'blue'       && 'bg-blue-500/20',
                    accentColor === 'amber'      && 'bg-amber-500/20',
                )}
            />

            <div className="relative flex items-center justify-between gap-2">
                <span className={cn(
                    'text-[10px] uppercase tracking-wider font-bold',
                    accentColor === 'ecf-yellow' && 'text-ecf-yellow',
                    accentColor === 'emerald'    && 'text-emerald-300',
                    accentColor === 'blue'       && 'text-blue-300',
                    accentColor === 'amber'      && 'text-amber-300',
                )}>
                    {titulo}
                </span>
                {Icone && (
                    <Icone size={16} className={cn(
                        accentColor === 'ecf-yellow' && 'text-ecf-yellow/70',
                        accentColor === 'emerald'    && 'text-emerald-300/70',
                        accentColor === 'blue'       && 'text-blue-300/70',
                        accentColor === 'amber'      && 'text-amber-300/70',
                    )} />
                )}
            </div>

            <div className="relative mt-4 flex items-baseline gap-2">
                <strong className="text-white text-4xl font-display font-black tabular-nums leading-none">
                    {valor ?? '—'}
                </strong>
                {trendDir === 'up'   && <TrendingUp   size={20} className="text-emerald-300" />}
                {trendDir === 'down' && <TrendingDown size={20} className="text-rose-300" />}
                {emBreve && (
                    <span className="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/30">
                        Em breve
                    </span>
                )}
            </div>

            {sublabel && (
                <p className="relative mt-auto text-white/50 text-xs pt-3">
                    {sublabel}
                </p>
            )}
        </div>
    );
}

/**
 * Card destaque de Faixa de Bônus — largura total abaixo da grid.
 * Mostra nota final + faixa colorida + badge promovida quando aplicável.
 */
function FaixaBonusCard({ data }) {
    const slug = data?.faixa_bonus;
    const nota = data?.nota_final;
    const promovida = data?.faixa_promovida === true;
    const cor = corFaixa(slug);

    return (
        <div className={cn(
            'relative overflow-hidden rounded-2xl border p-6 md:col-span-2 xl:col-span-4',
            cor.bg,
            cor.border,
        )}>
            {/* Glow */}
            <div className="absolute -top-24 -right-24 w-72 h-72 bg-ecf-yellow/[0.05] rounded-full blur-3xl pointer-events-none" />

            <div className="relative flex flex-col md:flex-row md:items-center gap-6">
                <div className="flex items-center gap-4">
                    <span className={cn(
                        'w-16 h-16 rounded-2xl flex items-center justify-center',
                        cor.bg, cor.border, 'border',
                    )}>
                        <Trophy size={26} className={cor.text} />
                    </span>
                    <div>
                        <span className="text-[10px] uppercase tracking-wider font-bold text-white/50">
                            Faixa de bônus
                        </span>
                        <div className="flex items-center gap-3 mt-1">
                            <h2 className={cn(
                                'text-3xl font-display font-black leading-none',
                                cor.text,
                            )}>
                                {faixaLabel(slug)}
                            </h2>
                            {promovida && (
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase bg-emerald-500/10 text-emerald-300 border border-emerald-500/30">
                                    <Sparkles size={11} />
                                    Promovida (2 meses consecutivos)
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="md:ml-auto flex items-baseline gap-2">
                    <span className="text-[10px] uppercase tracking-wider font-bold text-white/50">
                        Nota final
                    </span>
                    <span className="text-white text-4xl font-display font-black tabular-nums leading-none">
                        {nota != null ? Number(nota).toFixed(2) : '—'}
                    </span>
                    <span className="text-white/40 text-sm">/ 5,00</span>
                </div>
            </div>

            {nota == null && (
                <p className="relative mt-4 text-white/60 text-sm">
                    Sem dados suficientes para classificação — aguardando componentes do mês.
                </p>
            )}
        </div>
    );
}

// ═══ Página principal ═════════════════════════════════════════════════════

export default function DashboardCarteira({
    pessoa,
    periodo,
    desempenho,               // shape v2 do compute() — mês em curso (parcial)
    kpis,                     // derivados legados p/ compat (usado no ranking abaixo)
    nps,
    metas,
    empresas,
    nps_pendentes = [],
    // Props opcionais (backend pode fornecer no futuro; graceful fallback aqui)
    mes_atual,
    mes_fechado,
    resultado_mes_fechado,
    serie_diaria,
}) {
    const { auth } = usePage().props;

    // Estado do toggle de view — 'mes_atual' | 'mes_fechado' | 'diario' (D-18).
    // Default é mes_fechado quando disponível; senão mes_atual.
    const viewInicial = resultado_mes_fechado ? 'mes_fechado' : 'mes_atual';
    const [view, setView] = useState(viewInicial);

    // Dado ativo conforme view.
    const activeData = useMemo(() => {
        if (view === 'mes_fechado' && resultado_mes_fechado) return resultado_mes_fechado;
        return desempenho;
    }, [view, desempenho, resultado_mes_fechado]);

    // Extrai componentes do shape v2.
    const c = activeData?.componentes ?? {};
    const semCarteira = activeData?.sem_carteira === true;

    // Legenda do mês corrente (para header).
    const isoMesAtivo = view === 'mes_fechado'
        ? (mes_fechado ?? activeData?.mes_referencia)
        : (mes_atual ?? activeData?.mes_referencia ?? new Date().toISOString().slice(0, 10));
    const parcial = view === 'mes_atual';

    // Filtra série diária pra pontos com `mes_referencia === null` (rolling 30d).
    const serieDiariaFiltrada = useMemo(() => {
        return safeArray(serie_diaria).filter(p => p?.mes_referencia == null);
    }, [serie_diaria]);

    return (
        <AppLayout title="Desempenho — Carteira">
            <Head title={`Desempenho — ${pessoa?.nome ?? 'Carteira'}`} />

            <div className="p-6 max-w-7xl mx-auto space-y-6">
                {/* ═══ HEADER ═══════════════════════════════════════ */}
                <div className="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <div className="flex items-center gap-3">
                            {pessoa?.iniciais && (
                                <div className="w-11 h-11 rounded-lg bg-gradient-to-br from-ecf-yellow/20 to-yellow-500/5 border border-ecf-yellow/30 flex items-center justify-center text-ecf-yellow font-black text-sm">
                                    {pessoa.iniciais}
                                </div>
                            )}
                            <div>
                                <h1 className="text-white text-2xl font-display font-bold leading-none">
                                    Desempenho — Carteira
                                </h1>
                                <p className="text-white/60 text-sm mt-1">
                                    {pessoa?.nome} · {pessoa?.funcao}
                                    <span className="text-white/30"> · </span>
                                    <span className="text-white/70">
                                        {mesExtenso(isoMesAtivo)}
                                        {parcial && <span className="text-ecf-yellow"> (parcial)</span>}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Toggle de view (D-18) */}
                    <div className="inline-flex bg-ecf-card border border-white/[0.08] rounded-2xl p-1 gap-1">
                        {resultado_mes_fechado && (
                            <button
                                type="button"
                                onClick={() => setView('mes_fechado')}
                                className={cn(
                                    'px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors',
                                    view === 'mes_fechado'
                                        ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                        : 'text-white/50 hover:text-white',
                                )}
                            >
                                Mês fechado
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setView('mes_atual')}
                            className={cn(
                                'px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors',
                                view === 'mes_atual'
                                    ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                    : 'text-white/50 hover:text-white',
                            )}
                        >
                            Mês em curso
                        </button>
                        {serieDiariaFiltrada.length > 0 && (
                            <button
                                type="button"
                                onClick={() => setView('diario')}
                                className={cn(
                                    'px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors',
                                    view === 'diario'
                                        ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                        : 'text-white/50 hover:text-white',
                                )}
                            >
                                Diário (rolling 30d)
                            </button>
                        )}
                    </div>
                </div>

                {/* Banner "mês em curso — dados parciais" (Ajuste 2026-07-09) */}
                {!semCarteira && activeData?.periodo_meta?.em_curso && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex items-start gap-3">
                        <div className="w-8 h-8 rounded-lg bg-amber-500/15 flex items-center justify-center shrink-0">
                            <Calendar size={16} className="text-amber-300" />
                        </div>
                        <div className="text-sm">
                            <div className="text-amber-200 font-semibold">
                                Mês em curso — dados parciais
                            </div>
                            <div className="text-amber-100/70 text-xs mt-1 leading-relaxed">
                                Variações de faturamento e margem comparam
                                <span className="text-white font-medium"> dia 1 até {activeData.periodo_meta.dias_decorridos} </span>
                                do mês atual com o
                                <span className="text-white font-medium"> mesmo intervalo do mês anterior </span>
                                — comparação justa dia-a-dia (evita queda artificial por diferença de dias).
                                A nota consolidada oficial (para bônus) é definida quando o mês fecha
                                ({activeData.periodo_meta.dias_no_mes} dias completos).
                            </div>
                        </div>
                    </div>
                )}

                {/* ═══ SEM CARTEIRA (DESEMP-10) ═══════════════════════════ */}
                {semCarteira ? (
                    <div className="rounded-2xl border border-ecf-yellow/30 bg-ecf-yellow/[0.05] p-8 flex flex-col items-center text-center gap-3">
                        <UserX size={36} className="text-ecf-yellow" />
                        <h3 className="text-white text-xl font-display font-bold">
                            Sem carteira no período
                        </h3>
                        <p className="text-white/70 text-sm max-w-md">
                            {activeData?.motivo ?? `Você não possui empresas atribuídas em ${mesExtenso(isoMesAtivo)}.`}
                        </p>
                    </div>
                ) : (
                    <>
                        {/* ═══ 4 CARDS DE PARÂMETROS (DESEMP-04/05/06) ═══════════════ */}
                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <ParametroCard
                                icone={Star}
                                titulo="NPS médio"
                                valor={c.nps_medio != null ? Number(c.nps_medio).toFixed(2) : '—'}
                                sublabel="Nota média das respostas NPS no mês (escala 0-5)"
                                accentColor="ecf-yellow"
                            />

                            <ParametroCard
                                icone={c.var_faturamento_pct != null && c.var_faturamento_pct >= 0 ? TrendingUp : TrendingDown}
                                titulo="Faturamento"
                                valor={formatPercent(c.var_faturamento_pct)}
                                sublabel="Variação vs mês anterior · média por empresa"
                                accentColor="emerald"
                                trendDir={c.var_faturamento_pct != null ? (c.var_faturamento_pct >= 0 ? 'up' : 'down') : null}
                            />

                            <ParametroCard
                                icone={Coins}
                                titulo="Margem de contribuição"
                                valor={formatPercent(c.var_margem_pct)}
                                sublabel="Variação vs mês anterior · fonte Adman canônica"
                                accentColor="blue"
                                trendDir={c.var_margem_pct != null ? (c.var_margem_pct >= 0 ? 'up' : 'down') : null}
                            />

                            <ParametroCard
                                icone={Calendar}
                                titulo="Absenteísmo"
                                valor="—"
                                sublabel="Fonte de dados em definição"
                                accentColor="amber"
                                emBreve
                            />

                            {/* Faixa de bônus — full width abaixo dos 4 cards */}
                            <FaixaBonusCard data={activeData} />
                        </div>

                        {/* ═══ INFO CARTEIRA ═══════════════════════════════ */}
                        <div className="rounded-2xl bg-ecf-card border border-white/[0.08] p-5 flex items-center justify-between gap-4 flex-wrap">
                            <div className="flex items-center gap-3">
                                <Info size={16} className="text-white/40" />
                                <p className="text-white/70 text-sm">
                                    <strong className="text-white">{activeData?.empresas_com_baseline ?? 0}</strong>
                                    <span className="text-white/50"> empresas com baseline · </span>
                                    <strong className="text-white">{activeData?.empresas_carteira ?? 0}</strong>
                                    <span className="text-white/50"> na carteira</span>
                                </p>
                            </div>
                            <Link
                                href="/manual/desempenho-bonificacao"
                                className="inline-flex items-center gap-1.5 text-ecf-yellow text-xs font-semibold hover:underline"
                            >
                                <BookOpen size={12} />
                                Como calculamos?
                            </Link>
                        </div>
                    </>
                )}

                {/* ═══ NPS PENDENTES (Phase 72 Plan 03 v15.0) ═════════════════ */}
                <NpsPendingWidget pendentes={safeArray(nps_pendentes)} />

                {/* ═══ Legado — lista rápida de empresas em carteira ═════════
                    Mantida por compatibilidade com o payload atual do controller.
                    Continua útil como visão operacional (nome + status simples). */}
                {Array.isArray(empresas) && empresas.length > 0 && (
                    <div className="rounded-2xl bg-ecf-card border border-white/[0.08] overflow-hidden">
                        <div className="px-5 py-4 border-b border-white/[0.06] flex items-center gap-2">
                            <Trophy size={14} className="text-ecf-yellow/70" />
                            <h3 className="text-white text-sm font-bold">Empresas em carteira</h3>
                            <span className="text-white/40 text-xs ml-auto">
                                {empresas.length} {empresas.length === 1 ? 'empresa' : 'empresas'}
                            </span>
                        </div>
                        <div className="divide-y divide-white/[0.04]">
                            {empresas.slice(0, 20).map((e) => (
                                <div key={e.id ?? e.nome} className="px-5 py-3 flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-white text-sm font-semibold truncate">{e.nome}</p>
                                        <p className="text-white/40 text-[11px] truncate">{e.acao ?? ''}</p>
                                    </div>
                                    <div className="flex items-center gap-3 shrink-0">
                                        <span className="text-white/70 text-xs tabular-nums">
                                            {fmtBRLCompact(e.faturamento)}
                                        </span>
                                        {e.crescimento != null && (
                                            <span className={cn(
                                                'text-[11px] font-semibold tabular-nums',
                                                e.crescimento >= 0 ? 'text-emerald-300' : 'text-rose-300',
                                            )}>
                                                {formatPercent(e.crescimento)}
                                            </span>
                                        )}
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
