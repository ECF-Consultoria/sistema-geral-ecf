import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo, useEffect, useRef } from 'react';
import {
    ArrowLeft, Search, TrendingUp, TrendingDown, Building2,
    Briefcase, DollarSign, Calendar, Users, Trophy, Sparkles, ChevronRight, Info, Loader2,
} from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact, formatPercent } from '@/lib/utils';
// Spec 2026-08-14 (item 1) — régua ÚNICA de referência mensal do sistema.
import { rotuloMesReferencia } from '@/lib/referenciaMensal';
import { FonteBadge, StatusBadge, VarBadge } from '@/Pages/Portfolio/components/CarteiraBadges';
import PeriodoBanner from '@/Components/Desempenho/PeriodoBanner';
import {
    fmtNotaEmpresa, formatContaNota, faixaBonusLabel, faixaBonusCls,
    AVISO_SEM_DETALHE_TITULO, AVISO_SEM_DETALHE_EM_CURSO, avisoSemDetalheFechado,
    CONTA_NOTA_TOOLTIP,
} from '@/lib/desempenhoLabels';

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

// ─── KPI compacto ─────────────────────────────────────────────────────────
function KpiCard({ label, value, sub, icon: Icon, accent = 'text-white', badge }) {
    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wider font-semibold">
                {Icon && <Icon size={13} />}
                {label}
            </div>
            <div className="flex items-center gap-2 mt-2">
                <div className={cn('text-3xl font-bold tabular-nums', accent)}>
                    {value}
                </div>
                {badge}
            </div>
            {sub && <div className="text-white/40 text-xs mt-1">{sub}</div>}
        </div>
    );
}

// Badge de variação do nº de clientes no mês (entraram/saíram). Net do snapshot.
function ClientesBadge({ variacao }) {
    if (variacao === null || variacao === undefined || variacao === 0) {
        return (
            <span className="inline-flex items-center rounded-full border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 text-[11px] text-white/40">
                {variacao === 0 ? 'estável' : '—'}
            </span>
        );
    }
    const positivo = variacao > 0;
    const Icon = positivo ? TrendingUp : TrendingDown;
    return (
        <span
            title={positivo ? 'Clientes que entraram na carteira vs mês anterior' : 'Clientes que saíram da carteira vs mês anterior'}
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                positivo
                    ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300'
                    : 'border-rose-500/25 bg-rose-500/10 text-rose-300',
            )}
        >
            <Icon size={11} /> {positivo ? '+' : ''}{variacao}
        </span>
    );
}

/**
 * Ponto do mês — nota 0-5 + faixa de bônus + a conta que produziu a nota.
 *
 * Entrou em 2026-08-06 no lugar do KPI "Margem média (percentageMargin)": a
 * margem média é um dos insumos da nota, e exibi-la sozinha aqui obrigava a
 * abrir uma terceira tela para saber o que ela virou. A unidade de decisão do
 * time é PONTO, então é o ponto que ancora a carteira.
 *
 * A nota vem da MESMA precedência do `/performance/{id}` (snapshot congelado
 * antes de cálculo cacheado) — ver o comentário no controller. Aqui é só
 * apresentação; nenhum número é recalculado nesta tela.
 */
// `mesDetalhe` vem por PROP (o link daqui não enxerga o escopo do
// AdminCarteira); `score.calculando` = nota ainda sendo apurada no worker.
function NotaMesCard({ score, userId, mesDetalhe = null }) {
    const nota = score?.nota_final;
    const conta = formatContaNota(score?.pontos_componentes, nota);
    const semCarteira = score?.sem_carteira === true;
    const calculando = score?.calculando === true;

    // Enquanto calcula, o card mostra o estado em vez de "—": um traço aqui se
    // lê como "este profissional não tem nota", que é outra coisa.
    if (calculando) {
        return (
            <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5">
                <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wider font-semibold">
                    <Trophy size={13} />
                    Ponto do mês
                </div>
                <div className="flex items-center gap-2 mt-3">
                    <Loader2 size={18} className="text-ecf-yellow animate-spin shrink-0" />
                    <span className="text-white/70 text-sm font-medium">Calculando…</span>
                </div>
                <p className="text-white/40 text-xs mt-2 leading-relaxed">
                    Esta competência ainda não estava calculada. Roda em segundo plano,
                    empresa por empresa — o card se preenche sozinho.
                </p>
            </div>
        );
    }

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wider font-semibold">
                <Trophy size={13} />
                Ponto do mês
            </div>

            <div className="flex items-center gap-2 mt-2 flex-wrap">
                <div className="text-3xl font-bold tabular-nums text-white">
                    {semCarteira ? '—' : fmtNotaEmpresa(nota)}
                </div>
                {!semCarteira && nota != null && <span className="text-white/30 text-sm">/ 5,00</span>}
                {!semCarteira && (
                    <span className={cn(
                        'inline-flex items-center text-[10px] font-semibold px-2 py-0.5 rounded-full border',
                        faixaBonusCls(score?.faixa_bonus),
                    )}>
                        {faixaBonusLabel(score?.faixa_bonus)}
                    </span>
                )}
                {score?.faixa_promovida && (
                    <span
                        title="Promovida por 2 meses consecutivos em Intermediário"
                        className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full border bg-emerald-500/15 text-emerald-300 border-emerald-500/30"
                    >
                        <Sparkles size={9} />
                        PROMOVIDA
                    </span>
                )}
            </div>

            {semCarteira ? (
                <div className="text-white/40 text-xs mt-1">Sem carteira no mês selecionado.</div>
            ) : conta ? (
                <div
                    className="text-white/40 text-xs mt-1 tabular-nums"
                    title={CONTA_NOTA_TOOLTIP}
                >
                    {conta}
                </div>
            ) : (
                <div className="text-white/40 text-xs mt-1">Sem dados suficientes para classificação.</div>
            )}

            {/* Par inverso do link do card de bônus: lá se vai para a operação,
                aqui se volta para a formação da nota. Cada tela aponta para o
                que a outra tem de exclusivo. */}
            {userId && (
                <Link
                    href={route('performance.show', mesDetalhe ? { user: userId, mes: mesDetalhe } : { user: userId })}
                    className="inline-flex items-center gap-1.5 text-ecf-yellow text-xs font-semibold hover:underline mt-2.5 group"
                >
                    Ver como a nota foi formada
                    <ChevronRight size={12} className="text-ecf-yellow/60 group-hover:translate-x-0.5 transition-transform" />
                </Link>
            )}
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

export default function AdminCarteira({
    profissional, resumo, empresas = [], periodo, contexto = 'todos', bonus = null,
    score = null, tem_detalhe_empresas = false,
    // 2026-08-07 — true enquanto QUALQUER das duas camadas aquece em background
    // (nota e tabela têm caches distintos); `aquecendo_tabela` diz se é a tabela.
    // Mesma prop do ranking e do /performance/{id}.
    aquecendo = false,
    aquecendo_tabela = false,
}) {
    const [busca, setBusca] = useState('');
    const [sortCol, setSortCol] = useState('faturamento');
    const [sortDir, setSortDir] = useState('desc');

    // 2026-08-05 — o toggle "Em curso / Bônus atual / Mês fechado" saiu; o mês
    // passa a ser escolhido só pelo dropdown. `?modo=bonus_atual` continua
    // sendo aceito e preservado na navegação (links antigos), só não há mais
    // botão que o gere.
    const modoUrl = new URLSearchParams(window.location.search).get('modo');
    const modoBonusAtual = modoUrl === 'bonus_atual';

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

    // Mês a repassar no link "Ver como a nota foi formada" — null no mês
    // corrente, pra não trocar `current_month` pelo ramo `YYYY-MM` do
    // MetricPeriodResolver no destino.
    const mesDetalhe = (!periodo?.em_curso && periodo?.mes_selecionado)
        ? String(periodo.mes_selecionado).slice(0, 7)
        : null;

    // ── Poll de aquecimento (2026-08-07) ──────────────────────────────────
    // Espelha o do ranking (Fase 106) e o do /performance/{id}. Sem isso esta
    // tela chamava computeCached() de forma síncrona e pendurava o navegador
    // até 110s no cache frio — era o "carregando infinito" ao trocar o mês.
    const POLL_AQUECENDO_TETO = 20; // ~20 x 6s ≈ 2min
    const tentativasAquecendoRef = useRef(0);
    const [pollEsgotado, setPollEsgotado] = useState(false);

    useEffect(() => {
        if (!aquecendo) {
            tentativasAquecendoRef.current = 0;
            setPollEsgotado(false);
            return undefined;
        }
        const id = setInterval(() => {
            if (tentativasAquecendoRef.current >= POLL_AQUECENDO_TETO) {
                clearInterval(id);
                setPollEsgotado(true);
                return;
            }
            if (!document.hidden) {
                tentativasAquecendoRef.current += 1;
                router.reload({
                    only: ['score', 'aquecendo', 'aquecendo_tabela', 'tem_detalhe_empresas', 'empresas', 'resumo'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }
        }, 6000);
        return () => clearInterval(id);
    }, [aquecendo]);

    const recarregarAquecendoManual = () => {
        tentativasAquecendoRef.current = 0;
        setPollEsgotado(false);
        router.reload({
            only: ['score', 'aquecendo', 'aquecendo_tabela', 'tem_detalhe_empresas', 'empresas', 'resumo'],
            preserveScroll: true,
            preserveState: true,
        });
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

                {/* Contexto de período — uma linha, explicação no tooltip
                    (2026-08-06). Antes eram DOIS blocos aqui: a faixa de
                    competência do modo bônus e um parágrafo de 4 linhas sobre a
                    janela de comparação, o mesmo texto que existia em outras duas
                    telas. O intervalo comparado continua visível, agora ao lado do
                    rótulo em vez de dentro de um parágrafo. */}
                <PeriodoBanner
                    modo={modoBonusAtual && bonus ? 'bonus_atual' : (periodo?.em_curso ? 'em_curso' : 'mes_fechado')}
                    // Spec 2026-08-14 (item 1) — em competência fechada o rótulo
                    // vem da régua única (mês de acompanhamento + "Ref."). No mês
                    // em curso ainda não há competência fechada a referenciar,
                    // então segue o rótulo do período.
                    mesLabel={modoBonusAtual && bonus?.competence_month
                        ? rotuloMesReferencia(bonus.competence_month)
                        : periodo?.mes_label}
                    resumo={
                        modoBonusAtual && bonus
                            ? `competência ${formatCompetencia(bonus.competence_month)} · pago em ${formatMesSolo(bonus.payment_month)}`
                            : `${periodo?.range_atual ?? ''} vs ${periodo?.range_anterior ?? ''}`
                    }
                />

                {/* ─── KPIs principais ──────────────────────────────────── */}
                {/* Card "Empresas conectadas ao ML" removido — badge SVG na listagem
                    já mostra por empresa; card agregado era redundante. */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <KpiCard
                        label="Faturamento total"
                        value={formatCurrencyCompact(resumo?.total_faturamento ?? 0)}
                        sub={`Soma de ${resumo?.total_empresas ?? 0} empresas no período`}
                        icon={DollarSign}
                        accent="text-white"
                    />
                    <NotaMesCard score={score} userId={profissional?.id} mesDetalhe={mesDetalhe} />
                    <KpiCard
                        label="Clientes na carteira"
                        value={resumo?.total_empresas ?? 0}
                        sub={
                            resumo?.clientes_variacao == null
                                ? 'sem base do mês anterior para comparar'
                                : (resumo.clientes_variacao === 0
                                    ? 'sem entradas ou saídas no mês'
                                    : (resumo.clientes_variacao > 0
                                        ? `${resumo.clientes_variacao} cliente(s) entraram no mês`
                                        : `${Math.abs(resumo.clientes_variacao)} cliente(s) saíram no mês`))
                        }
                        icon={Users}
                        accent="text-white"
                        badge={<ClientesBadge variacao={resumo?.clientes_variacao} />}
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

                {/* Cards de margem em R$ removidos (2026-07-23, decisão do usuário):
                    a margem de contribuição em R$ não é mais exibida na carteira —
                    o que importa é a margem % (card "Margem média" acima) e a
                    variação dela. */}

                {/* ─── Listagem de empresas ─────────────────────────────── */}
                <Card className="bg-ecf-card border-white/[0.08]">
                    <CardContent className="p-5 space-y-4">
                        <div className="flex items-center justify-between gap-4 flex-wrap">
                            <div>
                                <h2 className="text-white font-semibold text-lg">Empresas em carteira</h2>
                                <p className="text-white/50 text-xs mt-0.5">
                                    Faturamento e variação de margem por empresa · fonte Adman (margem) / Shopee (faturamento, sem margem)
                                    {tem_detalhe_empresas && ' · pontos da régua abaixo de cada número'}
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

                        {/* Sem detalhe gravado, a tela DIZ que não tem — nunca calcula
                            por empresa na hora. Acionar o cálculo aqui reabriria o
                            fan-out de HTTP por empresa que já produziu página de 70s
                            neste módulo (learning §5). */}
                        {!tem_detalhe_empresas && (
                            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 flex items-start gap-3">
                                <Info size={16} className="text-white/40 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-white/80 text-sm font-semibold">{AVISO_SEM_DETALHE_TITULO}</p>
                                    <p className="text-white/50 text-xs mt-1 leading-relaxed">
                                        {periodo?.em_curso
                                            ? AVISO_SEM_DETALHE_EM_CURSO
                                            : avisoSemDetalheFechado(periodo?.mes_label ?? 'esta competência')}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Tabela aquecendo (2026-08-07) — os números por empresa
                            vêm de HTTP síncrono à Adman e estavam frios. Precisa
                            substituir a TABELA inteira: renderizá-la vazia diria
                            "carteira sem empresas", que é outra coisa. */}
                        {aquecendo_tabela ? (
                            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-8 flex flex-col items-center text-center gap-3">
                                <Loader2 size={28} className="text-ecf-yellow animate-spin" />
                                <p className="text-white/80 text-sm font-semibold">
                                    Carregando os números de {periodo?.mes_label ?? 'esta competência'}…
                                </p>
                                <p className="text-white/50 text-xs max-w-lg leading-relaxed">
                                    Faturamento e margem são buscados empresa por empresa e ainda não
                                    estavam em cache para este mês. A busca roda em segundo plano e a
                                    tabela aparece sozinha — normalmente em menos de dois minutos.
                                </p>
                                {pollEsgotado && (
                                    <button
                                        type="button"
                                        onClick={recarregarAquecendoManual}
                                        className="mt-1 rounded-lg border border-white/[0.12] bg-white/[0.04] px-4 py-2 text-sm text-white/80 hover:bg-white/[0.08] transition-colors"
                                    >
                                        Ainda carregando — verificar de novo
                                    </button>
                                )}
                            </div>
                        ) : (
                        <div className="overflow-x-auto -mx-1">
                            <table className="w-full text-[13px] min-w-[960px]">
                                <thead>
                                    <tr className="text-white/50 text-[11px] uppercase tracking-wider border-b border-white/[0.06]">
                                        <th className="text-left font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('name')}>Empresa</th>
                                        <th className="text-left font-semibold px-3 py-3">Fonte de dados</th>
                                        {tem_detalhe_empresas && (
                                            <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('nps_pontos')} title="Pontos de NPS da empresa no fechamento (régua 1-5)">NPS</th>
                                        )}
                                        <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('faturamento')}>Faturamento</th>
                                        <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('margem_pct')} title="Margem como % da receita (percentageMargin da Adman)">Margem %</th>
                                        {tem_detalhe_empresas && (
                                            <th className="text-right font-semibold px-3 py-3 cursor-pointer hover:text-white transition-colors" onClick={() => toggleSort('nota_empresa')} title="Nota da empresa no fechamento do mês">Nota</th>
                                        )}
                                        <th className="text-left font-semibold px-3 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {empresasView.length === 0 && (
                                        <tr>
                                            <td colSpan={tem_detalhe_empresas ? 7 : 5} className="text-center text-white/40 py-8">
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
                                            {/* Pontos da régua (só em competência fechada consolidada):
                                                sempre em texto pequeno ABAIXO do número operacional —
                                                a carteira responde pela operação, o ponto é a âncora. */}
                                            {tem_detalhe_empresas && (
                                                <td className="px-3 py-3 text-right tabular-nums">
                                                    <div className="text-white/80">{fmtNotaEmpresa(c.nps_pontos)}</div>
                                                    <div className="text-[10px] text-white/30">pontos</div>
                                                </td>
                                            )}
                                            <td className="px-3 py-3 text-right">
                                                <div className="text-white/90 tabular-nums">{c.faturamento !== null && c.faturamento !== undefined ? formatCurrencyCompact(c.faturamento) : '—'}</div>
                                                <div className="flex justify-end"><VarBadge v={c.faturamento_var_pct} /></div>
                                                {tem_detalhe_empresas && (
                                                    <div className="text-[10px] text-white/30 tabular-nums">{fmtNotaEmpresa(c.faturamento_pontos)} pontos</div>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-right" title={c.fonte === 'shopee' ? 'Shopee ainda não fornece margem' : undefined}>
                                                <div className="text-white/80 tabular-nums">{fmtPctVal(c.margem_pct)}</div>
                                                <div className="flex justify-end"><VarBadge v={c.margem_pct_var_pct} /></div>
                                                {tem_detalhe_empresas && (
                                                    <div className="text-[10px] text-white/30 tabular-nums">{fmtNotaEmpresa(c.margem_pontos)} pontos</div>
                                                )}
                                            </td>
                                            {tem_detalhe_empresas && (
                                                <td className="px-3 py-3 text-right tabular-nums font-bold text-white/90">
                                                    {fmtNotaEmpresa(c.nota_empresa)}
                                                </td>
                                            )}
                                            <td className="px-3 py-3"><StatusBadge status={c.status} invalidada={c.invalidada} /></td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        )}

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
