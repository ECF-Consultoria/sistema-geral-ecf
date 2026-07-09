import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ArrowLeft, Search, TrendingUp, TrendingDown, Building2,
    Briefcase, DollarSign, Coins, Calendar, Percent,
} from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact, formatPercent } from '@/lib/utils';

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
 * Contrato de props (do PortfolioController::renderAdminCarteira):
 *   profissional: { id, name, cargo_label }
 *   resumo: {
 *     total_empresas, empresas_ml_oauth, total_faturamento,
 *     total_margem_atual, total_margem_anterior, variacao_margem_pct
 *   }
 *   empresas: [{
 *     id, name, faturamento, margem_contribuicao, margem_contribuicao_anterior,
 *     margem_variacao_pct, has_ml_oauth
 *   }]
 *   periodo: {
 *     dia_atual, dias_no_mes, mes_label, range_atual, range_anterior
 *   }
 */

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

export default function AdminCarteira({ profissional, resumo, empresas = [], periodo }) {
    const [busca, setBusca] = useState('');
    const [sortCol, setSortCol] = useState('faturamento');
    const [sortDir, setSortDir] = useState('desc');

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
                <header className="flex items-center gap-4">
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
                            <div className="text-white/50 text-xs mt-1">
                                {profissional?.cargo_label} · {resumo?.total_empresas ?? 0} empresa{resumo?.total_empresas === 1 ? '' : 's'} em carteira
                            </div>
                        </div>
                    </div>
                </header>

                {/* ─── Banner "mês em curso" — comparação dia-a-dia justa ── */}
                <div className="rounded-xl border border-amber-500/25 bg-amber-500/[0.05] p-4 flex items-start gap-3">
                    <div className="w-8 h-8 rounded-lg bg-amber-500/15 flex items-center justify-center shrink-0">
                        <Calendar size={16} className="text-amber-300" />
                    </div>
                    <div className="text-sm">
                        <div className="text-amber-200 font-semibold capitalize">
                            {periodo?.mes_label ?? 'Mês em curso'} — comparação dia-a-dia
                        </div>
                        <div className="text-amber-100/70 text-xs mt-1 leading-relaxed">
                            Faturamento e margem comparam <span className="text-white font-medium">{periodo?.range_atual}</span>
                            {' '}com <span className="text-white font-medium">{periodo?.range_anterior}</span> do mês anterior — janela
                            do mesmo tamanho ({periodo?.dia_atual} dia{periodo?.dia_atual === 1 ? '' : 's'})
                            para evitar queda artificial. Nota consolidada oficial (para bônus) sai quando o mês fecha
                            ({periodo?.dias_no_mes} dias).
                        </div>
                    </div>
                </div>

                {/* ─── KPIs principais ──────────────────────────────────── */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <KpiCard
                        label="Faturamento total da carteira"
                        value={formatCurrencyCompact(resumo?.total_faturamento ?? 0)}
                        sub={`Soma de ${resumo?.total_empresas ?? 0} empresas no período`}
                        icon={DollarSign}
                        accent="text-white"
                    />
                    <KpiCard
                        label="Variação da margem de contribuição"
                        value={
                            resumo?.variacao_margem_pct !== null && resumo?.variacao_margem_pct !== undefined
                                ? `${resumo.variacao_margem_pct >= 0 ? '+' : ''}${resumo.variacao_margem_pct.toFixed(1)}%`
                                : '—'
                        }
                        sub="Total da carteira vs mesmo intervalo mês anterior"
                        icon={resumo?.variacao_margem_pct != null && resumo.variacao_margem_pct >= 0 ? TrendingUp : TrendingDown}
                        accent={
                            resumo?.variacao_margem_pct == null
                                ? 'text-white/50'
                                : resumo.variacao_margem_pct >= 0 ? 'text-emerald-300' : 'text-rose-300'
                        }
                    />
                    <KpiCard
                        label="Empresas conectadas ao ML"
                        value={`${resumo?.empresas_ml_oauth ?? 0} / ${resumo?.total_empresas ?? 0}`}
                        sub="Com OAuth vendedor ativo"
                        icon={Building2}
                        accent="text-white"
                    />
                </div>

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
                            <table className="w-full text-[13px]">
                                <thead>
                                    <tr className="text-white/50 text-[11px] uppercase tracking-wider border-b border-white/[0.06]">
                                        <th
                                            className="text-left font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors"
                                            onClick={() => toggleSort('name')}
                                        >
                                            Empresa
                                        </th>
                                        <th
                                            className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors"
                                            onClick={() => toggleSort('faturamento')}
                                        >
                                            Faturamento
                                        </th>
                                        <th
                                            className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors"
                                            onClick={() => toggleSort('margem_contribuicao')}
                                            title="Soma de contribution_margin (Adman) no período"
                                        >
                                            Margem R$
                                        </th>
                                        <th
                                            className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors"
                                            onClick={() => toggleSort('margem_variacao_pct')}
                                            title="Variação % vs mesmo intervalo mês anterior"
                                        >
                                            Var. margem
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {empresasView.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="text-center text-white/40 py-8">
                                                {busca ? 'Nenhuma empresa encontrada com esse filtro.' : 'Este profissional não tem empresas ativas em carteira.'}
                                            </td>
                                        </tr>
                                    )}

                                    {empresasView.map(c => (
                                        <tr key={c.id} className="border-b border-white/[0.04] hover:bg-white/[0.02]">
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={route('companies.show', c.id)}
                                                        className="text-white/90 hover:text-ecf-yellow font-medium"
                                                    >
                                                        {c.name}
                                                    </Link>
                                                    {/* Badge ML SVG quando OAuth vendedor ativo */}
                                                    {c.has_ml_oauth && (
                                                        <img
                                                            src="/images/mercado-livre-87.svg"
                                                            alt="Conectada ao Mercado Livre"
                                                            title="Conectada ao Mercado Livre via OAuth"
                                                            className="inline-block shrink-0"
                                                            style={{ width: 18, height: 18 }}
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-3 py-3 text-right text-white/90 tabular-nums">
                                                {c.faturamento !== null ? formatCurrencyCompact(c.faturamento) : '—'}
                                            </td>
                                            <td className="px-3 py-3 text-right text-white/70 tabular-nums">
                                                {formatCurrencyCompact(c.margem_contribuicao ?? 0)}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <VariacaoChip pct={c.margem_variacao_pct} />
                                            </td>
                                        </tr>
                                    ))}
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
