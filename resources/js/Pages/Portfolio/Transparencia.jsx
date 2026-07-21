import AppLayout from '@/Layouts/AppLayout';
import { router, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft, Building2, TrendingUp, TrendingDown, Minus, ShieldAlert,
    Trophy, Calendar, Info, Coins, Ban, ShoppingCart, Store,
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// Carteira de transparência (§8.3 · Fase 3 do plano de otimização · 2026-07-21)
//
// Página NOVA e aditiva — uma linha por empresa com fonte, faturamento, margem
// R$, margem %, variações (badges), status de qualidade e invalidação. Toda a
// numeração vem do AdmanMetricDiffService (fonte consistente nas duas janelas),
// alinhada ao contrato de período (?modo=/?mes=). Não substitui nenhuma tela
// existente — é pra avaliação.
// ═══════════════════════════════════════════════════════════════════════

const STATUS = {
    completo:     { label: 'Completo',            cls: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/25' },
    parcial:      { label: 'Parcial',             cls: 'bg-amber-500/10 text-amber-200 border-amber-500/25' },
    sem_baseline: { label: 'Sem baseline',        cls: 'bg-amber-500/10 text-amber-200/80 border-amber-500/20' },
    sem_dados:    { label: 'Sem dados Adman',     cls: 'bg-white/[0.04] text-white/50 border-white/[0.1]' },
    sem_fonte:    { label: 'Sem fonte financeira', cls: 'bg-sky-500/10 text-sky-300 border-sky-500/25' },
    invalidada:   { label: 'Invalidada p/ bônus', cls: 'bg-red-500/10 text-red-300 border-red-500/30' },
};

const fmtBRL = (n) => (n === null || n === undefined ? '—' : formatCurrency(n));
const fmtPctVar = (n) => (n === null || n === undefined ? null : `${n >= 0 ? '+' : ''}${Number(n).toFixed(1)}%`);
const fmtPctVal = (n) => (n === null || n === undefined ? '—' : `${Number(n).toFixed(1)}%`);

function mesExtenso(ym) {
    if (!ym) return '—';
    const [y, m] = String(ym).split('-');
    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}
const fmtDia = (iso) => (iso ? `${String(iso).slice(8, 10)}/${String(iso).slice(5, 7)}` : '');

// Badge de variação (crescimento/queda).
function VarBadge({ v }) {
    const txt = fmtPctVar(v);
    if (txt === null) return <span className="text-white/25 text-xs">—</span>;
    const up = Number(v) >= 0;
    const zero = Number(v) === 0;
    return (
        <span className={cn('inline-flex items-center gap-0.5 text-xs font-medium tabular-nums',
            zero ? 'text-white/40' : up ? 'text-emerald-400' : 'text-rose-400')}>
            {zero ? <Minus size={11} /> : up ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
            {txt}
        </span>
    );
}

function FonteBadge({ fonte, hasMlOauth }) {
    if (!fonte) return <span className="text-white/25 text-xs">—</span>;
    const isMl = fonte === 'ml' || fonte === 'mercado_livre' || hasMlOauth;
    return (
        <span className="inline-flex items-center gap-1 text-[11px] text-white/60">
            {isMl ? <Store size={12} className="text-yellow-400" /> : <Coins size={12} className="text-white/40" />}
            {fonte === 'adman' ? 'Adman' : isMl ? 'Mercado Livre' : fonte}
        </span>
    );
}

export default function Transparencia() {
    const { props } = usePage();
    const { profissional, contexto, empresas = [], resumo = {}, periodo = {}, bonus = null } = props;

    const isClosed = periodo?.is_closed === true;
    const modoAtivo = props.modo === 'bonus_atual' ? 'bonus_atual' : (isClosed ? 'mes_fechado' : 'em_curso');

    const irPara = (params) => router.get(route('portfolio.transparencia', profissional.id), params, { preserveScroll: true, preserveState: false });

    const seg = (active) => cn('px-3 h-8 rounded-lg transition-colors text-[13px]',
        active ? 'bg-ecf-yellow/15 text-ecf-yellow font-semibold' : 'text-white/50 hover:text-white/80');

    return (
        <AppLayout title={`Carteira — ${profissional?.name ?? ''}`}>
            <div className="mx-auto max-w-6xl px-4 py-6 space-y-5">

                {/* Header */}
                <div className="flex items-start justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-3">
                        <button type="button" onClick={() => router.visit(route('performance.show', profissional.id))}
                            className="flex items-center gap-1.5 text-white/40 hover:text-white text-[13px] transition-colors">
                            <ArrowLeft size={14} /> Desempenho
                        </button>
                        <span className="text-white/20">/</span>
                        <div>
                            <h1 className="text-white text-xl font-display font-extrabold leading-none">
                                Carteira de {profissional?.name}
                            </h1>
                            <p className="text-white/40 text-xs mt-1">Transparência por empresa · {mesExtenso(periodo.mes_selecionado)}</p>
                        </div>
                    </div>

                    {/* Segmento de período (mesmo contrato do ranking) */}
                    <div className="inline-flex rounded-xl border border-white/[0.08] bg-white/[0.03] p-0.5">
                        <button type="button" onClick={() => irPara({})} className={seg(modoAtivo === 'em_curso')}>Em curso</button>
                        <button type="button" onClick={() => irPara({ modo: 'bonus_atual' })} className={seg(modoAtivo === 'bonus_atual')}>Bônus atual</button>
                    </div>
                </div>

                {/* Banner de contexto */}
                {modoAtivo === 'bonus_atual' ? (
                    <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] p-3 flex items-center gap-2 text-sm">
                        <Trophy size={15} className="text-ecf-yellow shrink-0" />
                        <span className="text-white/70">
                            Base financeira do bônus — competência <strong className="text-white">{mesExtenso(bonus?.competence_month)}</strong>,
                            comparada com a janela anterior de mesmo tamanho ({fmtDia(periodo.baseline_start)}–{fmtDia(periodo.baseline_end)}).
                        </span>
                    </div>
                ) : (
                    <div className="rounded-xl border border-amber-500/25 bg-amber-500/[0.05] p-3 flex items-center gap-2 text-sm">
                        <Calendar size={15} className="text-amber-300 shrink-0" />
                        <span className="text-white/70">
                            Acompanhamento operacional — <strong className="text-white">{fmtDia(periodo.current_start)}–{fmtDia(periodo.current_end)}</strong> contra
                            o mesmo intervalo do mês anterior ({fmtDia(periodo.baseline_start)}–{fmtDia(periodo.baseline_end)}).
                        </span>
                    </div>
                )}

                {/* Resumo */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Empresas</p>
                        <p className="text-white text-2xl font-black tabular-nums mt-1">{resumo.total_empresas ?? 0}</p>
                    </div>
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Faturamento</p>
                        <p className="text-white text-2xl font-black tabular-nums mt-1">{fmtBRL(resumo.total_faturamento)}</p>
                    </div>
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Margem contrib. (R$)</p>
                        <p className="text-white text-2xl font-black tabular-nums mt-1">{fmtBRL(resumo.total_margem_rs)}</p>
                    </div>
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Invalidadas p/ bônus</p>
                        <p className={cn('text-2xl font-black tabular-nums mt-1', resumo.invalidadas > 0 ? 'text-red-300' : 'text-white/60')}>
                            {resumo.invalidadas ?? 0}
                        </p>
                    </div>
                </div>

                {/* Tabela */}
                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[820px]">
                            <thead>
                                <tr className="border-b border-white/[0.08] text-left text-[10px] uppercase tracking-wider text-white/40">
                                    <th className="px-4 py-3 font-semibold">Empresa</th>
                                    <th className="px-3 py-3 font-semibold">Fonte fat.</th>
                                    <th className="px-3 py-3 font-semibold text-right">Faturamento</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Margem de contribuição em R$ (fonte Adman)">Margem R$</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Margem de contribuição como % da receita (percentageMargin da Adman)">Margem %</th>
                                    <th className="px-3 py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {empresas.length === 0 && (
                                    <tr><td colSpan={6} className="px-4 py-10 text-center text-white/40">Nenhuma empresa na carteira neste contexto.</td></tr>
                                )}
                                {empresas.map((e) => {
                                    const st = STATUS[e.status] ?? STATUS.sem_dados;
                                    return (
                                        <tr key={e.id} className={cn('border-b border-white/[0.05] hover:bg-white/[0.02]', e.invalidada && 'bg-red-500/[0.04]')}>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Building2 size={14} className="text-white/25 shrink-0" />
                                                    <div className="min-w-0">
                                                        <div className="text-white/85 truncate">{e.name}</div>
                                                        <div className="flex flex-wrap gap-1 mt-0.5">
                                                            {e.servicos?.map((s, i) => (
                                                                <span key={i} className="inline-flex items-center gap-1 text-[10px] text-white/40 bg-white/[0.04] border border-white/[0.06] rounded px-1.5 py-0.5">
                                                                    {s.setor === 'shopee' ? <ShoppingCart size={9} /> : <Store size={9} />}
                                                                    {s.servico_nome ?? s.setor} · {s.role_label}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-3"><FonteBadge fonte={e.fonte_faturamento} hasMlOauth={e.has_ml_oauth} /></td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/85 tabular-nums">{fmtBRL(e.faturamento)}</div>
                                                <div className="flex justify-end"><VarBadge v={e.faturamento_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/85 tabular-nums">{fmtBRL(e.margem_rs)}</div>
                                                <div className="flex justify-end"><VarBadge v={e.margem_rs_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/85 tabular-nums">{fmtPctVal(e.margem_pct)}</div>
                                                <div className="flex justify-end"><VarBadge v={e.margem_pct_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span className={cn('inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full border', st.cls)}>
                                                    {e.invalidada && <Ban size={10} />}
                                                    {st.label}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Legenda / notas */}
                <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 text-xs text-white/50 leading-relaxed">
                    <div className="flex items-center gap-1.5 mb-1.5 text-white/70 font-semibold"><Info size={13} className="text-ecf-yellow/70" /> Como ler</div>
                    <strong className="text-white/70">Margem R$</strong> é o valor absoluto da margem de contribuição; <strong className="text-white/70">Margem %</strong> é a
                    margem como percentual da receita (percentageMargin da Adman). As setas mostram a variação vs a janela anterior de mesmo tamanho.
                    Empresas <strong className="text-white/70">só-Shopee</strong> aparecem como "sem fonte financeira" (não entram no financeiro do bônus enquanto a Shopee não entregar margem).
                    A margem vem sempre da <strong className="text-white/70">Adman</strong> (fonte canônica).
                </div>
            </div>
        </AppLayout>
    );
}
