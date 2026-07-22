import AppLayout from '@/Layouts/AppLayout';
import { router, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft, Building2, TrendingUp, TrendingDown, Minus, ShieldAlert,
    Trophy, Calendar, Info, Coins, Ban, ShoppingCart, Store,
} from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import { FonteBadge, StatusBadge, VarBadge } from '@/Pages/Portfolio/components/CarteiraBadges';

// ═══════════════════════════════════════════════════════════════════════
// Carteira de transparência (§8.3 · Fase 3 do plano de otimização · 2026-07-21)
//
// Página NOVA e aditiva — uma linha por empresa com fonte, faturamento, margem
// R$, margem %, variações (badges), status de qualidade e invalidação. Toda a
// numeração vem do AdmanMetricDiffService (fonte consistente nas duas janelas),
// alinhada ao contrato de período (?modo=/?mes=). Não substitui nenhuma tela
// existente — é pra avaliação.
// ═══════════════════════════════════════════════════════════════════════

const fmtBRL = (n) => (n === null || n === undefined ? '—' : formatCurrency(n));
const fmtPctVal = (n) => (n === null || n === undefined ? '—' : `${Number(n).toFixed(1)}%`);

function mesExtenso(ym) {
    if (!ym) return '—';
    const [y, m] = String(ym).split('-');
    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}
const fmtDia = (iso) => (iso ? `${String(iso).slice(8, 10)}/${String(iso).slice(5, 7)}` : '');

export default function Transparencia() {
    const { props } = usePage();
    const { profissional, empresas = [], resumo = {}, periodo = {}, bonus = null, meses_disponiveis = [] } = props;

    const isClosed = periodo?.is_closed === true;

    const irPara = (params) => router.get(route('portfolio.transparencia', profissional.id), params, { preserveScroll: true, preserveState: false });

    // Filtro é SÓ o mês: mês atual → em curso (sem param); mês passado → fechado.
    const trocarMes = (value) => {
        const m = meses_disponiveis.find((x) => x.value === value);
        if (m?.em_curso) irPara({}); else irPara({ mes: value });
    };

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

                    {/* Filtro: SÓ o mês. Atual = em curso; passado = base do bônus. */}
                    <div className="flex items-center gap-2">
                        <label className="text-white/40 text-xs">Mês:</label>
                        <select
                            value={periodo.mes_selecionado ?? ''}
                            onChange={(e) => trocarMes(e.target.value)}
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer capitalize"
                        >
                            {meses_disponiveis.map((m) => (
                                <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Banner de contexto — deduzido do mês (fechado = bônus; atual = operacional) */}
                {isClosed ? (
                    <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] p-3 flex items-center gap-2 text-sm">
                        <Trophy size={15} className="text-ecf-yellow shrink-0" />
                        <span className="text-white/70">
                            Mês fechado — base financeira do bônus (competência <strong className="text-white">{mesExtenso(bonus?.competence_month ?? periodo.mes_selecionado)}</strong>),
                            comparado com a janela anterior de mesmo tamanho ({fmtDia(periodo.baseline_start)}–{fmtDia(periodo.baseline_end)}).
                        </span>
                    </div>
                ) : (
                    <div className="rounded-xl border border-amber-500/25 bg-amber-500/[0.05] p-3 flex items-center gap-2 text-sm">
                        <Calendar size={15} className="text-amber-300 shrink-0" />
                        <span className="text-white/70">
                            Mês em curso — acompanhamento operacional. <strong className="text-white">{fmtDia(periodo.current_start)}–{fmtDia(periodo.current_end)}</strong> contra
                            o mesmo intervalo do mês anterior ({fmtDia(periodo.baseline_start)}–{fmtDia(periodo.baseline_end)}).
                        </span>
                    </div>
                )}

                {/* Resumo */}
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Empresas</p>
                        <p className="text-white text-2xl font-black tabular-nums mt-1">{resumo.total_empresas ?? 0}</p>
                    </div>
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <p className="text-white/40 text-[10px] uppercase tracking-wider">Faturamento</p>
                        <p className="text-white text-2xl font-black tabular-nums mt-1">{fmtBRL(resumo.total_faturamento)}</p>
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
                                    <th className="px-3 py-3 font-semibold">Fonte de dados</th>
                                    <th className="px-3 py-3 font-semibold text-right">Faturamento</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Margem de contribuição como % da receita (percentageMargin da Adman)">Margem %</th>
                                    <th className="px-3 py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {empresas.length === 0 && (
                                    <tr><td colSpan={5} className="px-4 py-10 text-center text-white/40">Nenhuma empresa na carteira neste contexto.</td></tr>
                                )}
                                {empresas.map((e) => {
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
                                            <td className="px-3 py-3"><FonteBadge fonte={e.fonte} /></td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/85 tabular-nums">{fmtBRL(e.faturamento)}</div>
                                                <div className="flex justify-end"><VarBadge v={e.faturamento_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/85 tabular-nums">{fmtPctVal(e.margem_pct)}</div>
                                                <div className="flex justify-end"><VarBadge v={e.margem_pct_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3"><StatusBadge status={e.status} invalidada={e.invalidada} /></td>
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
                    <strong className="text-white/70">Margem %</strong> é a margem de contribuição como percentual da receita (percentageMargin da Adman).
                    As setas mostram a variação vs a janela anterior de mesmo tamanho.
                    Empresas <strong className="text-white/70">só-Shopee</strong> aparecem como "sem fonte financeira" (não entram no financeiro do bônus enquanto a Shopee não entregar margem).
                    A margem vem sempre da <strong className="text-white/70">Adman</strong> (fonte canônica).
                </div>
            </div>
        </AppLayout>
    );
}
