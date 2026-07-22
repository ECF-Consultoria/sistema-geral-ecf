import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ArrowLeft, Search, TrendingUp, TrendingDown, Building2,
    Briefcase, DollarSign, Coins, Calendar, Percent,
} from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact, formatPercent } from '@/lib/utils';
import { FonteBadge, StatusBadge, VarBadge } from '@/Pages/Portfolio/components/CarteiraBadges';

// Margem % (valor) — "35.8%". Null → "—".
const fmtPctVal = (n) => (n === null || n === undefined ? '—' : `${Number(n).toFixed(1)}%`);

/**
 * `Portfolio/AdminCarteira.jsx` — view enxuta para admin/líder visualizar a
 * carteira de um analista/estrategista sem os widgets pessoais legados.
 *
 * Introduzida em 2026-07-09 como resposta ao feedback: /admin/users/{id}/portfolio
 * não estava renderizando os dados esperados no Portfolio/Show.jsx legado. Esta
 * página mostra APENAS o que o admin precisa pra auditar a carteira:
 *
 *   1. Total de faturamento somado das empresas em carteira
 *   2. Variação % da margem de contribuição (carteira toda) vs mesmo intervalo
 *      do mês anterior — comparação justa dia-a-dia acumulada
 *   3. Listagem de empresas com:
 *      - Nome (+ badge ML SVG quando OAuth vendedor ativo)
 *      - Faturamento no período
 *      - % variação de margem individual vs mesmo intervalo mês anterior
 *
 * Contrato de props (do PortfolioController::renderCarteiraProfissional):
 *   profissional: { id, name, cargo_label }
 *   resumo: {
 *     total_empresas, empresas_ml_oauth, empresas_sem_margem, total_faturamento,
 *     total_margem_atual, total_margem_anterior, variacao_margem_pct,
 *     total_ad_spend, tacos_medio
 *   }
 *   empresas: [{
 *     id, name, faturamento, margem_contribuicao, margem_contribuicao_anterior,
 *     margem_variacao_pct, ad_spend, tacos, has_ml_oauth,
 *     // Fase 89 (CART-01/02) — 1 entrada por vínculo de serviço da empresa.
 *     // Empresa com Performance+Shopee do mesmo profissional aparece 1x
 *     // aqui, com os 2 vínculos separados neste array.
 *     servicos: [{ servico_id, servico_nome, setor, role, role_label, financial_metrics_eligible }]
 *   }]
 *   periodo: {
 *     dia_atual, dias_no_mes, mes_label, range_atual, range_anterior
 *   }
 *
 * Nota: ad_spend/tacos ainda não são desenhados na tabela — payload já traz
 * esses campos, mas a coluna fica para uma fase futura.
 */

// Fase 89 (CART-02) — rótulo pt-BR nunca slug cru. Fallback pra setores
// futuros que ainda não tenham label consagrado nesta tela.
const SETOR_LABELS = {
    performance: 'Mercado Livre',
    shopee: 'Shopee',
};

// Fase 90 (CART-07) — mesma constante conceitual de Carteiras.jsx
// (CONTEXTO_OPTIONS), duplicada aqui de propósito — sem módulo compartilhado
// pra só 2 usos. Rótulos sem jargão, nunca slug cru (decisão travada).
const CONTEXTO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'performance', label: 'Mercado Livre' },
    { value: 'shopee', label: 'Shopee' },
];

// ─── Fase 104 (UIP-01/UIP-03/UIP-04) — toggle de período/contexto de bônus ─
// Rótulos travados pelo usuário — nunca renderizar slug cru ('em_curso'/
// 'bonus_atual'/'closed_period'/'official' na tela). Duplicado de propósito
// em Performance/Index.jsx e Carteiras.jsx (mesma convenção de CONTEXTO_OPTIONS).
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

const PERIODO_SEGMENTOS = [
    { key: 'em_curso', label: 'Em curso' },
    { key: 'bonus_atual', label: 'Bônus atual' },
    { key: 'mes_fechado', label: 'Mês fechado' },
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

// ─── KPI compacto ─────────────────────────────────────────────────────────
function KpiCard({ label, value, sub, icon: Icon, accent = 'text-white' }) {
    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wider font-semibold">
                {Icon && <Icon size={13} />}
                {label}
            </div>
            <div className={cn('text-3xl font-bold tabular-nums mt-2', accent)}>
                {value}
            </div>
            {sub && <div className="text-white/40 text-xs mt-1">{sub}</div>}
        </div>
    );
}

// ─── Chip de variação (+/-) ───────────────────────────────────────────────
function VariacaoChip({ pct, size = 'sm' }) {
    if (pct === null || pct === undefined) {
        return <span className="text-white/40 tabular-nums">—</span>;
    }
    const positivo = pct >= 0;
    const Icon = positivo ? TrendingUp : TrendingDown;
    return (
        <span className={cn(
            'inline-flex items-center gap-1 tabular-nums font-semibold',
            positivo ? 'text-emerald-300' : 'text-rose-300',
            size === 'sm' ? 'text-[13px]' : 'text-lg',
        )}>
            <Icon size={size === 'sm' ? 12 : 16} />
            {positivo ? '+' : ''}{pct.toFixed(1)}%
        </span>
    );
}

export default function AdminCarteira({ profissional, resumo, empresas = [], periodo, contexto = 'todos', bonus = null }) {
    const [busca, setBusca] = useState('');
    const [sortCol, setSortCol] = useState('faturamento');
    const [sortDir, setSortDir] = useState('desc');

    // Fase 104 (UIP-01) — segmento ativo lido da URL (?modo=), não de estado
    // local — reflete exatamente o que o backend resolveu.
    const modoUrl = new URLSearchParams(window.location.search).get('modo');
    const segmentoAtivo = modoUrl === 'bonus_atual'
        ? 'bonus_atual'
        : (periodo?.em_curso ? 'em_curso' : 'mes_fechado');

    // Navegação unificada — preserva ?contexto= e, quando presente, ?modo=,
    // sobrescrevendo só o que o `overrides` pedir. Usada pelos 3 controles
    // de período (contexto, mês, toggle) pra nenhum perder o estado dos outros.
    const navigate = (overrides) => {
        const base = { contexto, mes: periodo?.mes_selecionado ?? '', modo: modoUrl ?? undefined };
        const params = new URLSearchParams();
        Object.entries({ ...base, ...overrides }).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') params.set(k, v);
        });
        router.visit(window.location.pathname + '?' + params.toString(), { preserveScroll: true });
    };

    // Troca de segmento do toggle — 'mes_fechado' sem seleção prévia cai no
    // mês fechado mais recente disponível na lista já carregada.
    const applyPeriodo = (segmento) => {
        if (segmento === 'bonus_atual') {
            navigate({ modo: 'bonus_atual', mes: undefined });
        } else if (segmento === 'mes_fechado') {
            const fallback = periodo?.meses_disponiveis?.find((m) => !m.em_curso)?.value;
            navigate({ modo: undefined, mes: periodo?.em_curso ? fallback : periodo?.mes_selecionado });
        } else {
            navigate({ modo: undefined, mes: undefined });
        }
    };

    const empresasView = useMemo(() => {
        const q = busca.trim().toLowerCase();
        let arr = (empresas || []).filter(c => !q || c.name.toLowerCase().includes(q));
        arr = [...arr].sort((a, b) => {
            const va = a[sortCol];
            const vb = b[sortCol];
            const aNull = va === null || va === undefined;
            const bNull = vb === null || vb === undefined;
            if (aNull && bNull) return 0;
            if (aNull) return 1;   // nulls no final
            if (bNull) return -1;
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });
        return arr;
    }, [empresas, busca, sortCol, sortDir]);

    const toggleSort = (col) => {
        if (sortCol === col) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortCol(col);
            setSortDir('desc');
        }
    };

    return (
        <AppLayout title={`Carteira de ${profissional?.name ?? 'Profissional'}`}>
            <div className="max-w-[1400px] mx-auto p-6 space-y-6">

                {/* ─── Cabeçalho ─────────────────────────────────────────── */}
                <header className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex items-center gap-4">
                        <button
                            type="button"
                            onClick={() => router.visit(route('portfolio.own'))}
                            className="inline-flex items-center gap-1.5 text-white/50 hover:text-white text-[13px] transition-colors"
                        >
                            <ArrowLeft size={14} /> Voltar
                        </button>
                        <span className="text-white/20">/</span>
                        <div className="flex items-center gap-3">
                            <div className="h-11 w-11 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/25 flex items-center justify-center">
                                <Briefcase className="h-5 w-5 text-ecf-yellow" />
                            </div>
                            <div>
                                <div className="text-white/40 text-[11px] uppercase tracking-widest font-semibold">
                                    Carteira do profissional
                                </div>
                                <h1 className="text-white text-xl font-display font-extrabold leading-none mt-1">
                                    {profissional?.name}
                                </h1>
                                <div className="text-white/50 text-xs mt-1 flex items-center gap-2 flex-wrap">
                                    <span>
                                        {profissional?.cargo_label} · {resumo?.total_empresas ?? 0} empresa{resumo?.total_empresas === 1 ? '' : 's'} única{resumo?.total_empresas === 1 ? '' : 's'} · {resumo?.vinculos_servico ?? 0} vínculo{(resumo?.vinculos_servico ?? 0) === 1 ? '' : 's'} de serviço
                                    </span>
                                    {/* Chip âmbar (CART-07/SC4) — mesmo tom do chip da tabela abaixo.
                                        Renderização defensiva: props podem faltar em cache de página antiga. */}
                                    {(resumo?.vinculos_sem_fonte_financeira ?? 0) > 0 && (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-amber-500/10 text-amber-300/80 border-amber-500/20">
                                            {resumo.vinculos_sem_fonte_financeira} sem fonte financeira
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 flex-wrap">
                        {/* Fase 104 (UIP-01) — toggle de contexto de período: Em curso /
                            Bônus atual / Mês fechado (usa o dropdown de mês ao lado). */}
                        <PeriodoToggle segmentoAtivo={segmentoAtivo} onSelect={applyPeriodo} />

                        {/* Fase 90 (CART-07) · filtro de contexto (Todos/Mercado Livre/Shopee),
                            preserva o ?mes= e ?modo= ativos ao trocar (decisão travada). */}
                        <div className="flex items-center gap-2">
                            <span className="text-white/40 text-[11px] uppercase tracking-widest font-semibold">Contexto</span>
                            <select
                                value={contexto}
                                onChange={(e) => navigate({ contexto: e.target.value })}
                                title="Filtrar por contexto de serviço"
                                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer"
                            >
                                {CONTEXTO_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                            </select>
                        </div>

                        {/* Ajuste 2026-07-09 · filtro de mês (audita bônus consolidados).
                            Fase 90 — passa a preservar ?contexto= ativo ao trocar de mês.
                            Fase 104 — trocar o mês manualmente sai do modo "Bônus atual". */}
                        {Array.isArray(periodo?.meses_disponiveis) && periodo.meses_disponiveis.length > 0 && (
                            <div className="flex items-center gap-2">
                                <span className="text-white/40 text-[11px] uppercase tracking-widest font-semibold">Mês</span>
                                <select
                                    value={periodo?.mes_selecionado ?? ''}
                                    onChange={(e) => navigate({ mes: e.target.value, modo: undefined })}
                                    title="Selecionar mês da carteira"
                                    className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer capitalize"
                                >
                                    {periodo.meses_disponiveis.map((m) => (
                                        <option key={m.value} value={m.value}>
                                            {m.label}{m.em_curso ? ' (em curso)' : ''}
                                        </option>
                                    ))}
                                </select>
                                {periodo?.em_curso ? (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-amber-500/15 text-amber-300 border border-amber-500/30 tracking-wider">
                                        Em curso
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 tracking-wider">
                                        Fechado
                                    </span>
                                )}
                            </div>
                        )}
                    </div>
                </header>

                {/* Fase 104 (UIP-03) — no modo Bônus atual, deixa explícito qual
                    competência está sendo avaliada e quando ela é paga. */}
                {segmentoAtivo === 'bonus_atual' && bonus && (
                    <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] px-4 py-2.5 text-ecf-yellow/90 text-sm font-medium">
                        Competência {formatCompetencia(bonus.competence_month)} · pago em {formatMesSolo(bonus.payment_month)}
                    </div>
                )}

                {/* ─── Banner de contexto do período — muda conforme mês em curso vs fechado ─── */}
                <div className={cn(
                    'rounded-xl border p-4 flex items-start gap-3',
                    periodo?.em_curso
                        ? 'border-amber-500/25 bg-amber-500/[0.05]'
                        : 'border-emerald-500/25 bg-emerald-500/[0.05]',
                )}>
                    <div className={cn(
                        'w-8 h-8 rounded-lg flex items-center justify-center shrink-0',
                        periodo?.em_curso ? 'bg-amber-500/15' : 'bg-emerald-500/15',
                    )}>
                        <Calendar size={16} className={periodo?.em_curso ? 'text-amber-300' : 'text-emerald-300'} />
                    </div>
                    <div className="text-sm">
                        <div className={cn(
                            'font-semibold capitalize',
                            periodo?.em_curso ? 'text-amber-200' : 'text-emerald-200',
                        )}>
                            {periodo?.mes_label ?? 'Mês em curso'} {periodo?.em_curso ? '— comparação dia-a-dia' : '— mês fechado'}
                        </div>
                        <div className={cn(
                            'text-xs mt-1 leading-relaxed',
                            periodo?.em_curso ? 'text-amber-100/70' : 'text-emerald-100/70',
                        )}>
                            {periodo?.em_curso ? (
                                <>
                                    Faturamento e margem comparam <span className="text-white font-medium">{periodo?.range_atual}</span>
                                    {' '}com <span className="text-white font-medium">{periodo?.range_anterior}</span> do mês anterior — janela
                                    do mesmo tamanho ({periodo?.dia_atual} dia{periodo?.dia_atual === 1 ? '' : 's'})
                                    para evitar queda artificial. Nota consolidada oficial (para bônus) sai quando o mês fecha
                                    ({periodo?.dias_no_mes} dias).
                                </>
                            ) : (
                                <>
                                    Dados consolidados do mês fechado — comparam <span className="text-white font-medium">{periodo?.range_atual}</span>
                                    {' '}com <span className="text-white font-medium">{periodo?.range_anterior}</span>.
                                    Estes são os valores usados na régua de bônus daquele mês.
                                </>
                            )}
                        </div>
                    </div>
                </div>

                {/* ─── KPIs principais ──────────────────────────────────── */}
                {/* Card "Empresas conectadas ao ML" removido — badge SVG na listagem
                    já mostra por empresa; card agregado era redundante. */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <KpiCard
                        label="Faturamento total da carteira"
                        value={formatCurrencyCompact(resumo?.total_faturamento ?? 0)}
                        sub={`Soma de ${resumo?.total_empresas ?? 0} empresas no período`}
                        icon={DollarSign}
                        accent="text-white"
                    />
                    <KpiCard
                        label="Variação da margem %"
                        value={
                            resumo?.variacao_margem_pct !== null && resumo?.variacao_margem_pct !== undefined
                                ? `${resumo.variacao_margem_pct >= 0 ? '+' : ''}${resumo.variacao_margem_pct.toFixed(1)}%`
                                : '—'
                        }
                        sub="Média das variações de margem % por empresa (mesma métrica do bônus)"
                        icon={resumo?.variacao_margem_pct != null && resumo.variacao_margem_pct >= 0 ? TrendingUp : TrendingDown}
                        accent={
                            resumo?.variacao_margem_pct == null
                                ? 'text-white/50'
                                : resumo.variacao_margem_pct >= 0 ? 'text-emerald-300' : 'text-rose-300'
                        }
                    />
                </div>

                {/* Aviso quando há empresas sem dados de margem — transparência sobre
                    qualidade dos dados. Explica por que algumas linhas mostram "—". */}
                {(resumo?.empresas_sem_margem ?? 0) > 0 && (
                    <div className="rounded-xl border border-rose-500/25 bg-rose-500/[0.05] p-4 flex items-start gap-3">
                        <div className="w-8 h-8 rounded-lg bg-rose-500/15 flex items-center justify-center shrink-0">
                            <TrendingDown size={16} className="text-rose-300" />
                        </div>
                        <div className="text-sm">
                            <div className="text-rose-200 font-semibold">
                                {resumo.empresas_sem_margem} empresa{resumo.empresas_sem_margem === 1 ? '' : 's'} sem dados de margem no período
                            </div>
                            <div className="text-rose-100/70 text-xs mt-1 leading-relaxed">
                                Ocorre quando o sync Adman ainda não rodou pra empresa OU quando a Adman
                                não reportou <code className="bg-white/10 rounded px-1">contribution_margin</code>{' '}
                                (custo de produto não cadastrado, pedido apenas com custo zero, etc). Essas
                                empresas mostram <span className="text-white font-mono">—</span> na tabela e
                                <span className="text-white"> não entram no cálculo</span> da variação de
                                margem da carteira — evita o falso -100% que poluia a régua de bônus.
                            </div>
                        </div>
                    </div>
                )}

                {/* ─── Margem absoluta (contexto adicional) ─────────────── */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <KpiCard
                        label="Margem no período"
                        value={formatCurrencyCompact(resumo?.total_margem_atual ?? 0)}
                        sub={`Acumulado ${periodo?.range_atual ?? ''}`}
                        icon={Coins}
                        accent="text-emerald-200"
                    />
                    <KpiCard
                        label="Margem mesmo intervalo mês anterior"
                        value={formatCurrencyCompact(resumo?.total_margem_anterior ?? 0)}
                        sub={`Baseline ${periodo?.range_anterior ?? ''}`}
                        icon={Coins}
                        accent="text-white/60"
                    />
                </div>

                {/* ─── Listagem de empresas ─────────────────────────────── */}
                <Card className="bg-ecf-card border-white/[0.08]">
                    <CardContent className="p-5 space-y-4">
                        <div className="flex items-center justify-between gap-4 flex-wrap">
                            <div>
                                <h2 className="text-white font-semibold text-lg">Empresas em carteira</h2>
                                <p className="text-white/50 text-xs mt-0.5">
                                    Faturamento e variação de margem por empresa · fonte Adman (canônica)
                                </p>
                            </div>
                            <div className="flex items-center gap-2 flex-1 max-w-xs">
                                <Search className="h-4 w-4 text-white/40" />
                                <input
                                    type="text"
                                    value={busca}
                                    onChange={(e) => setBusca(e.target.value)}
                                    placeholder="Buscar empresa…"
                                    className="w-full bg-white/[0.03] border border-white/[0.08] rounded-lg px-3 py-1.5 text-white text-sm focus:outline-none focus:ring-2 focus:ring-ecf-yellow/30"
                                />
                            </div>
                        </div>

                        <div className="overflow-x-auto -mx-1">
                            <table className="w-full text-[13px] min-w-[840px]">
                                <thead>
                                    <tr className="text-white/50 text-[11px] uppercase tracking-wider border-b border-white/[0.06]">
                                        <th className="text-left font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('name')}>Empresa</th>
                                        <th className="text-left font-semibold px-3 py-3">Fonte de dados</th>
                                        <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('faturamento')}>Faturamento</th>
                                        <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('margem_pct')} title="Margem como % da receita (percentageMargin da Adman)">Margem %</th>
                                        <th className="text-left font-semibold px-3 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {empresasView.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-white/40 py-8">
                                                {busca ? 'Nenhuma empresa encontrada com esse filtro.' : 'Este profissional não tem empresas ativas em carteira.'}
                                            </td>
                                        </tr>
                                    )}

                                    {empresasView.map(c => {
                                        const servicosLinha = c.servicos ?? [];
                                        return (
                                        <tr key={c.id} className={cn('border-b border-white/[0.04] hover:bg-white/[0.02]', c.invalidada && 'bg-red-500/[0.04]')}>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <Link href={route('companies.show', c.id)} className="text-white/90 hover:text-ecf-yellow font-medium">
                                                        {c.name}
                                                    </Link>
                                                    {c.has_ml_oauth && (
                                                        <img src="/images/mercado-livre-87.svg" alt="Conectada ao Mercado Livre" title="Conectada ao Mercado Livre via OAuth" className="inline-block shrink-0" style={{ width: 16, height: 16 }} />
                                                    )}
                                                </div>
                                                {servicosLinha.length > 0 && (
                                                    <div className="flex items-center gap-1.5 flex-wrap mt-1.5">
                                                        {servicosLinha.map((s, idx) => (
                                                            <span
                                                                key={`${c.id}-servico-${s.servico_id ?? 'legado'}-${idx}`}
                                                                className={cn(
                                                                    'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border',
                                                                    s.financial_metrics_eligible
                                                                        ? 'bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/25'
                                                                        : 'bg-amber-500/10 text-amber-300/80 border-amber-500/20',
                                                                )}
                                                            >
                                                                {SETOR_LABELS[s.setor] ?? s.servico_nome ?? 'Outro serviço'} · {s.role_label}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-3"><FonteBadge fonte={c.fonte} /></td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/90 tabular-nums">{c.faturamento !== null && c.faturamento !== undefined ? formatCurrencyCompact(c.faturamento) : '—'}</div>
                                                <div className="flex justify-end"><VarBadge v={c.faturamento_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/80 tabular-nums">{fmtPctVal(c.margem_pct)}</div>
                                                <div className="flex justify-end"><VarBadge v={c.margem_pct_var_pct} /></div>
                                            </td>
                                            <td className="px-3 py-3"><StatusBadge status={c.status} invalidada={c.invalidada} /></td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {empresasView.length > 0 && (
                            <div className="text-white/40 text-[11px] pt-2 border-t border-white/[0.04]">
                                Mostrando {empresasView.length} de {empresas.length} empresa{empresas.length === 1 ? '' : 's'}.
                                Clique nos cabeçalhos para ordenar.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
