import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import {
    ArrowLeft, Star, TrendingUp, TrendingDown, Coins, Calendar,
    Trophy, Sparkles, UserX, BookOpen, Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Phase 74 D-19 · Plan 74-06 · view individual (analista/estrategista).
 *
 * Consome o shape v2 do DesempenhoScoreService:
 *   { user, resultado (compute() shape), mes_selecionado, mes_fechado,
 *     meses_disponiveis: string[] }
 *
 * Card por parâmetro (NPS/Faturamento/Margem/Absenteísmo) + card destaque
 * Faixa de bônus + placeholder "Em breve" no Absenteísmo (DESEMP-06) + toggle
 * de mês para navegar em fechamentos anteriores (D-19). Sem carteira →
 * badge amarelo grande "Sem carteira em {mês}" (DESEMP-10).
 */

// ─── Helpers ─────────────────────────────────────────────────────────────

const FAIXA_LABEL = {
    sem_bonus:     'Sem bônus',
    basico:        'Básico',
    intermediario: 'Intermediário',
    maximo:        'Máximo',
};
const FAIXA_COR = {
    sem_bonus:     { bg: 'bg-white/[0.04]',   border: 'border-white/[0.08]',   text: 'text-white/60' },
    basico:        { bg: 'bg-sky-500/10',     border: 'border-sky-500/30',     text: 'text-sky-300' },
    intermediario: { bg: 'bg-violet-500/10',  border: 'border-violet-500/30',  text: 'text-violet-300' },
    maximo:        { bg: 'bg-ecf-yellow/10',  border: 'border-ecf-yellow/40',  text: 'text-ecf-yellow' },
};
function faixaLabel(slug) {
    if (!slug) return 'Sem classificação';
    return FAIXA_LABEL[slug] ?? slug;
}
function corFaixa(slug) {
    return FAIXA_COR[slug] ?? FAIXA_COR.sem_bonus;
}

function mesExtenso(iso) {
    if (!iso) return '—';
    try {
        const [y, m] = String(iso).split('-');
        const d = new Date(Number(y), Number(m) - 1, 1);
        return d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    } catch {
        return String(iso);
    }
}

function formatPercent(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    const n = Number(v);
    return `${n >= 0 ? '+' : ''}${n.toFixed(1)}%`;
}

// ─── Card de parâmetro ───────────────────────────────────────────────────
function ParametroCard({ icone: Icone, titulo, valor, sublabel, accentColor = 'ecf-yellow', emBreve = false, trendDir }) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-ecf-card border border-white/[0.08] p-6 min-h-[168px] flex flex-col">
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
                {Icone && <Icone size={16} className="text-white/50" />}
            </div>

            <div className="relative mt-4 flex items-baseline gap-2 flex-wrap">
                <strong className="text-white text-4xl font-display font-black tabular-nums leading-none">
                    {valor ?? '—'}
                </strong>
                {trendDir === 'up'   && <TrendingUp   size={18} className="text-emerald-300" />}
                {trendDir === 'down' && <TrendingDown size={18} className="text-rose-300" />}
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

// ─── Card destaque Faixa de bônus ────────────────────────────────────────
function FaixaBonusCard({ resultado }) {
    const slug = resultado?.faixa_bonus;
    const nota = resultado?.nota_final;
    const promovida = resultado?.faixa_promovida === true;
    const cor = corFaixa(slug);

    return (
        <div className={cn(
            'relative overflow-hidden rounded-2xl border p-6 md:col-span-2 xl:col-span-4',
            cor.bg,
            cor.border,
        )}>
            <div className="absolute -top-24 -right-24 w-72 h-72 bg-ecf-yellow/[0.05] rounded-full blur-3xl pointer-events-none" />

            <div className="relative flex flex-col md:flex-row md:items-center gap-6">
                <div className="flex items-center gap-4">
                    <span className={cn(
                        'w-16 h-16 rounded-2xl flex items-center justify-center border',
                        cor.bg, cor.border,
                    )}>
                        <Trophy size={26} className={cor.text} />
                    </span>
                    <div>
                        <span className="text-[10px] uppercase tracking-wider font-bold text-white/50">
                            Faixa de bônus
                        </span>
                        <div className="flex items-center gap-3 mt-1 flex-wrap">
                            <h2 className={cn('text-3xl font-display font-black leading-none', cor.text)}>
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
                    Sem dados suficientes para classificação no mês selecionado.
                </p>
            )}
        </div>
    );
}

// ─── Página principal ────────────────────────────────────────────────────
export default function PerformanceShow({
    user,
    resultado = {},
    mes_selecionado,
    mes_fechado,
    meses_disponiveis = [],
}) {
    const c = resultado?.componentes ?? {};
    const semCarteira = resultado?.sem_carteira === true;

    // Handler de troca de mês (query param).
    const trocarMes = (iso) => {
        router.get(
            route('performance.show', user.id),
            { mes: iso },
            { preserveState: false, preserveScroll: true },
        );
    };

    return (
        <AppLayout title={`Desempenho — ${user?.name ?? 'Analista'}`}>
            <div className="space-y-6 max-w-6xl mx-auto">

                {/* Breadcrumb + Header + Toggle de mês */}
                <div className="flex items-start justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => router.visit(route('performance.index'))}
                            className="flex items-center gap-1.5 text-white/40 hover:text-white text-[13px] transition-colors"
                        >
                            <ArrowLeft size={14} /> Ranking
                        </button>
                        <span className="text-white/20">/</span>
                        <div>
                            <h1 className="text-white text-xl font-display font-extrabold leading-none">
                                Desempenho de {user?.name}
                            </h1>
                            <p className="text-white/50 text-sm mt-1">
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-white/[0.04] border border-white/[0.08] px-2 py-0.5 text-[11px] font-semibold text-white/70">
                                    {user?.cargo_label ?? 'Analista'}
                                </span>
                                <span className="text-white/30 mx-2">·</span>
                                <span className="text-white/70">{mesExtenso(mes_selecionado ?? resultado?.mes_referencia)}</span>
                            </p>
                        </div>
                    </div>

                    {/* Toggle de mês fechado (D-19) */}
                    {Array.isArray(meses_disponiveis) && meses_disponiveis.length > 0 && (
                        <div className="flex items-center gap-2">
                            <label className="text-white/50 text-xs">Mês:</label>
                            <select
                                value={mes_selecionado ?? ''}
                                onChange={(e) => trocarMes(e.target.value)}
                                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer"
                            >
                                {meses_disponiveis.map(m => (
                                    <option key={m} value={m}>{mesExtenso(m)}</option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {/* Banner "mês em curso — dados parciais" (Ajuste 2026-07-09) */}
                {!semCarteira && resultado?.periodo_meta?.em_curso && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex items-start gap-3">
                        <div className="w-8 h-8 rounded-lg bg-amber-500/15 flex items-center justify-center shrink-0">
                            <Calendar size={16} className="text-amber-300" />
                        </div>
                        <div className="text-sm">
                            <div className="text-amber-200 font-semibold">
                                Mês em curso — dados parciais
                            </div>
                            <div className="text-amber-100/70 text-xs mt-1 leading-relaxed">
                                As variações de faturamento e margem comparam
                                <span className="text-white font-medium"> dia 1 até {resultado.periodo_meta.dias_decorridos} </span>
                                do mês atual com o
                                <span className="text-white font-medium"> mesmo intervalo do mês anterior </span>
                                — comparação justa dia-a-dia.
                                A nota consolidada oficial (usada para bônus) só é calculada quando o mês fecha
                                ({resultado.periodo_meta.dias_no_mes} dias completos).
                            </div>
                        </div>
                    </div>
                )}

                {/* SEM CARTEIRA — bloco amarelo grande (DESEMP-10) */}
                {semCarteira ? (
                    <div className="rounded-2xl border border-ecf-yellow/30 bg-ecf-yellow/[0.05] p-10 flex flex-col items-center text-center gap-3">
                        <UserX size={40} className="text-ecf-yellow" />
                        <h3 className="text-white text-2xl font-display font-bold">
                            Sem carteira em {mesExtenso(resultado?.mes_referencia ?? mes_selecionado)}
                        </h3>
                        <p className="text-white/70 text-sm max-w-md">
                            {resultado?.motivo ?? 'Este profissional não possui empresas atribuídas no mês selecionado.'}
                        </p>
                    </div>
                ) : (
                    <>
                        {/* 4 CARDS DE PARÂMETROS */}
                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <ParametroCard
                                icone={Star}
                                titulo="NPS médio"
                                valor={c.nps_medio != null ? Number(c.nps_medio).toFixed(2) : '—'}
                                sublabel="Média das respostas NPS no mês (escala 0-5). Sem respostas → 0."
                                accentColor="ecf-yellow"
                            />

                            <ParametroCard
                                icone={c.var_faturamento_pct != null && c.var_faturamento_pct >= 0 ? TrendingUp : TrendingDown}
                                titulo="Faturamento"
                                valor={formatPercent(c.var_faturamento_pct)}
                                sublabel="Variação vs mês anterior · média das % por empresa da carteira"
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

                            {/* Faixa de bônus */}
                            <FaixaBonusCard resultado={resultado} />
                        </div>

                        {/* Info carteira */}
                        <div className="rounded-2xl bg-ecf-card border border-white/[0.08] p-5 flex items-center justify-between gap-4 flex-wrap">
                            <div className="flex items-center gap-3">
                                <Info size={16} className="text-white/40" />
                                <p className="text-white/70 text-sm">
                                    <strong className="text-white">{resultado?.empresas_com_baseline ?? 0}</strong>
                                    <span className="text-white/50"> empresas com baseline · </span>
                                    <strong className="text-white">{resultado?.empresas_carteira ?? 0}</strong>
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

                {/* Bloco explicativo permanente */}
                <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
                    <div className="flex items-center gap-2 mb-2">
                        <Info size={14} className="text-ecf-yellow/70" />
                        <span className="text-white/70 text-sm font-semibold">Como interpretar</span>
                    </div>
                    <p className="text-white/60 text-sm leading-relaxed">
                        A nota final é a média direta dos parâmetros disponíveis (NPS · Var. Faturamento · Var. Margem). O
                        Absenteísmo está em <em>standby</em> nesta versão. A faixa de bônus é configurável pelo admin —
                        detalhes em{' '}
                        <Link href="/manual/desempenho-bonificacao" className="text-ecf-yellow hover:underline">
                            /manual/desempenho-bonificacao
                        </Link>.
                    </p>
                </div>

            </div>
        </AppLayout>
    );
}
