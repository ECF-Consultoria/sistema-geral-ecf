import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Tv, X, ExternalLink, Loader2, AlertTriangle } from 'lucide-react';
import { formatCurrency, formatPercent, cn } from '@/lib/utils';
import { SourceBadge } from '@/Components/ui/source-badge';
// Fase 97 Plan 03 (DASH-97-1/DASH-97-2) — painel de filtros rascunho→aplicar
// + chips + fix da navegação do marketplace. PERIOD_OPTIONS reexportado daqui
// para o modo TV (abaixo) e o header usarem o MESMO conjunto de períodos.
import FiltrosDashboard, { PERIOD_OPTIONS } from '@/Components/Dashboard/FiltrosDashboard';
// Fase 97 Plan 04 (DASH-97-4/5/6/7) — gráfico interativo Faturamento/Margem +
// cards "NPS ruim"/"Score da equipe"/"Novas empresas no mês".
import ChartEvolucao from '@/Components/Dashboard/ChartEvolucao';
import NpsRuimCarrossel from '@/Components/Dashboard/NpsRuimCarrossel';
import ScoreEquipe from '@/Components/Dashboard/ScoreEquipe';
import NovasEmpresas from '@/Components/Dashboard/NovasEmpresas';

// Fase 97 (2026-07-21) — KPI com GLOW blur na cor da métrica + delta vs
// período anterior. Reutilizado no modo normal E no modo TV para os dois
// layouts ficarem idênticos. O delta é computado DENTRO do componente (por
// card) — pitfall Rollup: nunca herdar flag derivada de escopo externo.
function KpiGlowCard({ def, noData }) {
    let delta = null;
    if (def.deltaPct !== undefined) {
        const semBase = def.deltaPct === null || def.deltaPct === undefined;
        const dir = semBase ? 'neutral' : (def.deltaPct >= 0 ? 'good' : 'bad');
        delta = { dir, arrow: dir === 'good' ? '▲' : dir === 'bad' ? '▼' : '•', label: semBase ? 'sem base' : `${def.deltaPct > 0 ? '+' : ''}${def.deltaPct.toFixed(1)}%` };
    } else if (def.deltaPp !== undefined) {
        const semBase = def.deltaPp === null || def.deltaPp === undefined;
        const dir = semBase ? 'neutral' : (def.deltaPp >= 0 ? 'good' : 'bad');
        delta = { dir, arrow: dir === 'good' ? '▲' : dir === 'bad' ? '▼' : '•', label: semBase ? 'sem base' : `${def.deltaPp > 0 ? '+' : ''}${def.deltaPp.toFixed(1)} pp` };
    }
    const deltaColors = { good: 'text-emerald-400 bg-emerald-500/10', bad: 'text-red-400 bg-red-500/10', neutral: 'text-white/50 bg-white/[0.06]' };
    return (
        <div className="group relative card-ecf rounded-2xl p-4 overflow-hidden">
            <div
                className="pointer-events-none absolute -top-14 left-1/2 -translate-x-1/2 h-28 w-[78%] rounded-full blur-2xl opacity-[0.22] group-hover:opacity-45 transition-opacity duration-300"
                style={{ background: def.topBar }}
            />
            <div className="relative z-10 flex flex-col gap-2.5">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-white/40 text-[10.5px] font-bold uppercase tracking-wide">{def.label}</p>
                    <Link href={def.href} title={def.linkTitle} className="w-5 h-5 rounded-md flex items-center justify-center text-white/30 hover:text-ecf-yellow hover:bg-white/[0.06] transition-colors shrink-0">
                        <ExternalLink size={12} />
                    </Link>
                </div>
                <p className={cn('font-display font-extrabold text-2xl tracking-tight', noData ? 'text-white/20' : 'text-white')}>{noData ? '—' : def.value}</p>
                {!noData && (
                    <div className="flex items-center gap-2 flex-wrap">
                        {delta && (
                            <span className={cn('inline-flex items-center gap-1 text-[11.5px] font-bold px-1.5 py-0.5 rounded-md', deltaColors[delta.dir])}>
                                {delta.arrow} {delta.label}
                            </span>
                        )}
                        <span className="text-white/30 text-[11px]">{def.legendaSemDelta || def.sub}</span>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ─── main ─────────────────────────────────────────────── */

export default function AdminDashboard({
    stats = {},
    revenue_chart = [],
    tacos_chart = [],
    // Fase 97 Plan 01 — série diária de margem ponderada (mesmo eixo do
    // revenue_chart), consumida pela aba "Margem" do ChartEvolucao (Plan 97-04).
    margin_chart = [],
    nps_distribution = { positivas: 0, negativas: 0 },
    performance_equipe = [],
    period = '30',
    filters = {},
    users = [],
    analistas = [],
    estrategistas = [],
    combinacoes = [],
    companies_list = [],
    grupos_list = [],
    companies_performance = [],
    ranking = [],
    adman_last_sync = null,
    // Phase 72 Plan 03 v15.0 — lista de empresas pendentes de NPS este mês
    // (injetada pelo DashboardController via NpsPendingService::forCarteira)
    nps_pendentes = [],
    // Fase 97 Plan 02 (DASH-97-5) — respostas de NPS nota baixa do recorte
    // (já excluindo invalidadas, Fase 96). Usado aqui só para "M ruins" do
    // KPI de NPS; o carrossel completo é do Plan 97-04.
    nps_ruins = [],
    // Fase 97 Plan 02 (DASH-97-7) — cards das empresas com contrato ativo
    // iniciado no mês corrente (D3). Widget "Novas empresas no mês" (Plan 97-04).
    novas_empresas = [],
    // Fase 97 Plan 01 — nome da rota Inertia atual ('dashboard' ou
    // 'mercadolivre.dashboard'). Base do fix do bug do marketplace: navegar
    // sempre pela rota CORRENTE, nunca por uma rota fixa (Fase 97 Riscos §1).
    dashboard_route_name = 'dashboard',
}) {
    const [tvMode, setTvMode] = useState(false);

    // Fase 97 Plan 04 — estados REAIS de carregando/erro (sem toggle de
    // demo): `isNavigating` acompanha uma navegação Inertia em andamento
    // (aplicar filtro, remover chip); `navError` acompanha uma falha de
    // rede/servidor durante essa navegação. `router.on` já é o padrão do
    // projeto (ver resources/js/app.jsx — before/success globais).
    const [isNavigating, setIsNavigating] = useState(false);
    const [navError, setNavError] = useState(false);
    useEffect(() => {
        const offStart = router.on('start', () => { setIsNavigating(true); setNavError(false); });
        const offFinish = router.on('finish', () => setIsNavigating(false));
        const offError = router.on('error', () => setNavError(true));
        return () => { offStart(); offFinish(); offError(); };
    }, []);

    // Phase 61-05 (DASH-04/DATA-05) — legenda multi-fonte no header.
    // Guard `sourceCounts &&` mantém backward compat quando flag OFF (undefined).
    const sourceCounts = stats?.source_counts ?? null;

    // Fase 97 Plan 03 (DASH-97-2) — FIX do bug do marketplace: o antigo
    // `applyFilter` usava `route('dashboard')` fixo, então filtrar em
    // /dashboard/mercadolivre caía na dashboard genérica e perdia o recorte
    // `marketplace=meli` (Fase 97 CONTEXT Riscos §1). Agora navega SEMPRE
    // pela rota corrente (`dashboard_route_name`, vindo do backend) e
    // preserva `filters.marketplace` explicitamente — nunca inventado pelo
    // front, sempre o valor que o próprio backend validou (threat T-97-03-01).
    const applyFilters = (next) => {
        router.get(route(dashboard_route_name), {
            period: next.period,
            company_id: next.company_id || undefined,
            group_id: next.group_id || undefined,
            consultor_id: next.consultor_id || undefined,
            estrategista_id: next.estrategista_id || undefined,
            marketplace: filters.marketplace || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const s = {
        total_companies:         stats.total_companies ?? 0,
        avg_tacos:               stats.avg_tacos ?? 0,
        avg_nps:                 stats.avg_nps ?? 0,
        absenteeism_rate:        stats.absenteeism_rate ?? 0,
        total_revenue:           stats.total_revenue ?? 0,
        total_ad_investment_30d: stats.total_ad_investment_30d ?? 0,
        total_net_billing:       stats.total_net_billing ?? 0,
        total_sold_quantity:     stats.total_sold_quantity ?? 0,
        total_ad_spend:          stats.total_ad_spend ?? 0,
        avg_margin:              stats.avg_margin ?? 0,
        avg_profit_share:        stats.avg_profit_share ?? 0,
    };

    const noData = s.total_companies === 0;

    // Total de respostas de NPS do recorte (base do "N respostas" no KPI).
    const npsTotal = (nps_distribution.positivas ?? 0) + (nps_distribution.negativas ?? 0);

    // Fase 97 Plan 03 (DASH-97-3) — "M ruins" do KPI de NPS. Usa a contagem
    // real de `nps_ruins` (Plan 97-02, já filtrado pelo recorte e sem
    // invalidadas) quando disponível; cai em `nps_distribution.negativas`
    // como fallback (compat com payloads antigos/flag OFF).
    const npsRuinsCount = Array.isArray(nps_ruins) ? nps_ruins.length : (nps_distribution.negativas ?? 0);

    const periodLabel = PERIOD_OPTIONS.find(p => p.value === period)?.label ?? '';

    // Fase 97 Plan 04 (DASH-97-4) — nome da empresa quando o filtro `company_id`
    // está ativo, para o subtítulo do ChartEvolucao ("... · Nome da Empresa"
    // em vez de "· N empresas"). `companies_list` já vem completo do backend.
    const empresaFiltrada = filters.company_id
        ? companies_list.find(c => String(c.id) === String(filters.company_id))
        : null;

    // Fase 97 — definições dos 4 KPIs (Faturamento, Margem, NPS, Empresas),
    // usadas TANTO no modo normal QUANTO no modo TV (layouts idênticos).
    const kpiDefs = [
        { key: 'faturamento', label: 'Faturamento total', value: formatCurrency(s.total_revenue), deltaPct: stats.total_revenue_delta_pct, legendaSemDelta: 'vs período anterior', href: route('companies.index'), linkTitle: 'Performance por empresa', topBar: '#60a5fa' },
        { key: 'margem', label: 'Margem contrib. média', value: formatPercent(s.avg_margin), deltaPp: stats.avg_margin_delta_pp, legendaSemDelta: 'ponderada por faturamento', href: route('performance.index'), linkTitle: 'Margem por profissional (Área da equipe)', topBar: '#34d399' },
        { key: 'nps', label: 'NPS médio', value: (s.avg_nps ?? 0).toFixed(2), sub: `${npsTotal} respostas · ${npsRuinsCount} ruins`, href: route('nps.index'), linkTitle: 'Respostas de NPS', topBar: '#22c55e' },
        { key: 'empresas', label: 'Empresas ativas', value: String(s.total_companies), sub: `+${stats.novas_empresas_count ?? 0} novas no mês`, href: route('companies.index'), linkTitle: 'Cadastro de empresas', topBar: '#ffe600' },
    ];

    /* ── TV MODE ─────────────────────────────────────────── */
    // Reproduz o MESMO layout do modo normal (KPIs com glow + gráfico
    // Evolução + Score da equipe + NPS ruim), em fullscreen sem a sidebar.
    if (tvMode) {
        return (
            <div className="fixed inset-0 bg-[#050507] z-50 flex flex-col gap-4 p-6 overflow-auto">
                {/* Glow de identidade */}
                <div className="pointer-events-none fixed -top-24 right-10 h-96 w-96 rounded-full blur-[140px] opacity-[0.07] bg-ecf-yellow" />

                {/* Header */}
                <div className="relative flex items-center justify-between shrink-0">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2.5">
                            <div className="w-9 h-9 rounded-xl bg-ecf-yellow flex items-center justify-center">
                                <span className="text-[#252525] font-display font-extrabold text-base">E</span>
                            </div>
                            <div>
                                <p className="text-white font-display font-extrabold text-xl tracking-tight">ECF Consultoria</p>
                                <p className="text-white/30 text-xs">{periodLabel} · {new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' })}</p>
                            </div>
                        </div>
                        <div className="h-8 w-[2px] bg-ecf-grad opacity-60 rounded-full" />
                        <p className="text-ecf-yellow/60 text-sm font-semibold tracking-widest uppercase">Mercado Livre</p>
                    </div>
                    <button
                        onClick={() => setTvMode(false)}
                        className="flex items-center gap-2 text-white/40 hover:text-white text-sm border border-white/10 hover:border-white/20 rounded-xl px-3 py-2 transition-all"
                    >
                        <X size={14} /> Sair do modo TV
                    </button>
                </div>

                {/* KPIs — mesmos do modo normal */}
                <div className="relative grid grid-cols-2 lg:grid-cols-4 gap-3 shrink-0">
                    {kpiDefs.map(k => <KpiGlowCard key={k.key} def={k} noData={noData} />)}
                </div>

                {/* Gráfico Evolução + Score da equipe */}
                <div className="relative grid grid-cols-1 lg:grid-cols-[2.1fr_1fr] gap-4 items-stretch">
                    <ChartEvolucao
                        revenueChart={revenue_chart}
                        marginChart={margin_chart}
                        periodLabel={periodLabel}
                        companiesCount={s.total_companies}
                        companyName={empresaFiltrada?.name ?? null}
                    />
                    <ScoreEquipe membros={performance_equipe} />
                </div>

                {/* NPS ruim — largura total */}
                <div className="relative shrink-0">
                    <NpsRuimCarrossel respostas={nps_ruins} />
                </div>
            </div>
        );
    }

    /* ── NORMAL MODE ─────────────────────────────────────── */
    return (
        <AppLayout title="Dashboard Mercado Livre">
            <div className="relative space-y-5">

                {/* Glow de identidade — brilho suave contido no container (não
                    vaza pra sidebar). Dá o "ar" da marca sem poluir. */}
                <div className="pointer-events-none absolute -top-20 right-4 h-72 w-72 rounded-full blur-[120px] opacity-[0.07] bg-ecf-yellow" />
                <div className="pointer-events-none absolute top-40 -left-10 h-64 w-64 rounded-full blur-[120px] opacity-[0.05] bg-blue-500" />

                {/* Barra de filtros + controles. Cabeçalho/ícone removidos a
                    pedido (2026-07-21) — a dashboard entra direto no conteúdo. */}
                <div className="relative flex items-center gap-3 flex-wrap">
                    <FiltrosDashboard
                        period={period}
                        filters={filters}
                        companiesList={companies_list}
                        gruposList={grupos_list}
                        analistas={analistas}
                        estrategistas={estrategistas}
                        combinacoes={combinacoes}
                        onApply={applyFilters}
                    />

                    <div className="flex items-center gap-2 shrink-0 ml-auto">
                        {/* Estado REAL de "carregando" (navegação Inertia em andamento). */}
                        {isNavigating && (
                            <div className="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/[0.06] text-ecf-yellow text-[12px] font-semibold">
                                <Loader2 size={12} className="animate-spin" />
                                Atualizando…
                            </div>
                        )}
                        {/* Phase 61-05 — Legenda multi-fonte (só com a flag ligada). */}
                        {sourceCounts && (
                            <div className="flex items-center gap-2 text-[11px] text-white/50 flex-wrap" data-testid="dashboard-source-legend">
                                <span className="uppercase tracking-wider">Fontes:</span>
                                {sourceCounts.ml > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="ml" />{sourceCounts.ml}</span>}
                                {sourceCounts.unified > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="unified" />{sourceCounts.unified}</span>}
                                {sourceCounts.adman > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="adman" />{sourceCounts.adman}</span>}
                                {sourceCounts.none > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="none" />{sourceCounts.none}</span>}
                            </div>
                        )}
                        {/* Indicador de atualização (D-1 Adman). */}
                        <div
                            title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. Sincronização automática diária às 11h."
                            className="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white/50 text-[12px]"
                        >
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.7)]" />
                            {adman_last_sync
                                ? `Atualizado em ${adman_last_sync.label} · D-1 da Adman`
                                : 'D-1 da Adman · sem sync ainda'}
                        </div>
                        <button
                            onClick={() => setTvMode(true)}
                            className="flex items-center gap-1.5 h-9 px-3 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all"
                        >
                            <Tv size={13} /> Modo TV
                        </button>
                    </div>
                </div>

                {/* Fase 97 Plan 04 — estado REAL de "erro" (sem toggle de demo):
                    quando uma navegação Inertia (aplicar filtro) falha de verdade
                    (`router.on('error')`), troca todo o conteúdo abaixo por um bloco
                    com "Tentar novamente" (recarrega o recorte atual via router.reload). */}
                {navError ? (
                    <div className="rounded-2xl border border-red-500/20 bg-red-500/[0.03] px-6 py-14 flex flex-col items-center text-center gap-3">
                        <div className="w-12 h-12 rounded-full bg-red-500/15 flex items-center justify-center">
                            <AlertTriangle className="text-red-400" size={22} />
                        </div>
                        <p className="text-white/85 text-[15px] font-bold">Não foi possível carregar os dados do recorte</p>
                        <p className="text-white/40 text-[13px] max-w-[380px]">
                            A conexão com a base do setor falhou ao aplicar o filtro. Os últimos dados exibidos podem estar desatualizados.
                        </p>
                        <button
                            type="button"
                            onClick={() => router.reload()}
                            className="mt-1 h-10 px-4 rounded-xl bg-ecf-yellow text-[#252525] text-[13px] font-bold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all"
                        >
                            Tentar novamente
                        </button>
                    </div>
                ) : (
                <div className={cn('space-y-6 transition-opacity', isNavigating && 'opacity-50 pointer-events-none')}>

                {/* Empty state banner */}
                {noData && (
                    <div className="rounded-2xl border border-ecf-yellow/10 bg-ecf-yellow/[0.03] px-5 py-4 flex items-center gap-3">
                        <div className="w-1.5 h-8 bg-ecf-grad rounded-full" />
                        <div>
                            <p className="text-white/80 text-sm font-semibold">Nenhum dado disponível</p>
                            <p className="text-white/30 text-xs mt-0.5">Cadastre empresas e configure a API Adman para ver as métricas aqui.</p>
                        </div>
                    </div>
                )}

                {/* KPI Cards — Fase 97: 4 indicadores com glow + variação vs.
                    período anterior + link. `KpiGlowCard`/`kpiDefs` são
                    compartilhados com o modo TV. */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {kpiDefs.map(k => <KpiGlowCard key={k.key} def={k} noData={noData} />)}
                </div>

                {/* Fase 97 — "Evolução no período" em LARGURA TOTAL (mock): abas
                    Faturamento/Margem (D4) + hover (tooltip + Pico/Menor). O
                    BarChart horizontal antigo de "Desempenho da equipe" foi
                    REMOVIDO — o card "Score da equipe" (abaixo) é o substituto. */}
                <ChartEvolucao
                    revenueChart={revenue_chart}
                    marginChart={margin_chart}
                    periodLabel={periodLabel}
                    companiesCount={s.total_companies}
                    companyName={empresaFiltrada?.name ?? null}
                />

                {/* Fase 97 Plan 04 (DASH-97-5/DASH-97-6) — linha 2.1fr/1fr do mockup:
                    "NPS ruim" (carrossel) + "Score da equipe" (nota 0-5 + breakdown,
                    pior→melhor). */}
                <div className="grid grid-cols-1 lg:grid-cols-[2.1fr_1fr] gap-4 items-stretch">
                    <NpsRuimCarrossel respostas={nps_ruins} />
                    <ScoreEquipe membros={performance_equipe} />
                </div>

                {/* Fase 97 Plan 04 (DASH-97-7) — "Novas empresas no mês", largura
                    total e CONDICIONAL (o próprio componente retorna null se vazio). */}
                <NovasEmpresas empresas={novas_empresas} />

                {/* Fase 97 (rework 2026-07-21) — os widgets antigos "TACOS média
                    por período", "Performance por empresa" (tabela) e "Empresas
                    pendentes de NPS" foram REMOVIDOS desta dashboard para bater com
                    o mock: o dado de TACOS/empresa vive no drill-down (KPIs linkam
                    para /companies e /performance) e o NPS pendente foi superado
                    pelo card "NPS ruim". */}

                </div>
                )}

            </div>
        </AppLayout>
    );
}
