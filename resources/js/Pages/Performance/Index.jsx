import AppLayout from '@/Layouts/AppLayout';
import { router, Link, usePage } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef } from 'react';
import {
    Trophy, ChevronDown, TrendingUp, CheckSquare, ChevronRight, X,
    Users, Target, CheckCircle2, Crown, Award, ShoppingCart, Percent,
    ArrowUp, ArrowDown, Minus, Flame, Clock, Megaphone, BarChart3,
    Gauge, Activity, Tv, TrendingDown, Rocket, Sparkles, Info, BookOpen,
    UserX, ChevronsUp, Settings,
} from 'lucide-react';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import { cn, formatPercent as fmtPctUtil, formatCurrency } from '@/lib/utils';
import HeroKpi from '@/Pages/Polos/components/HeroKpi';
import RadialGauge from '@/Pages/Polos/components/RadialGauge';

// ═══════════════════════════════════════════════════════════════════════
// Phase 74 (D-18/D-19/D-20) — Ranking de Desempenho v2
//
// Reescreve a seção Consultoria pra consumir shape v2 do DesempenhoScoreService
// (nota_final, faixa_bonus, componentes.*). Preserva por completo a seção
// Publicações (POLOS) — dashboard executivo de TV — usada em rota separada.
//
// Regras da Phase 74:
//  - DESEMP-10 — usuários com sem_carteira=true SÃO removidos do ranking
//    exibido. O bloco de transparência "excluídos" lista os nomes ao fim
//    da página pra o admin saber quem ficou fora e por quê.
//  - DESEMP-14 — filtro mes_referencia >= 2026-08-01 é responsabilidade do
//    controller (Plan 74-04). Aqui só renderizamos o que veio.
//  - Ranking do MÊS FECHADO mais recente (D-20). Se não houver fechamento
//    ainda, subtítulo indica "mês em curso (parcial)".
// ═══════════════════════════════════════════════════════════════════════

const pubRoleLabel = { publicador: 'Publicador', lider: 'Líder POLOS' };

const STATUS_COLOR = {
    'Acima da meta':  'text-emerald-400',
    'No alvo':        'text-ecf-yellow',
    'Abaixo da meta': 'text-red-400',
};

// ─── Labels/cores das faixas de bônus (Phase 74 D-07) ────────────────────
const FAIXA_LABEL = {
    sem_bonus:     'Sem bônus',
    basico:        'Básico',
    intermediario: 'Intermediário',
    maximo:        'Máximo',
};
const FAIXA_BADGE_CLS = {
    sem_bonus:     'bg-white/[0.04] text-white/60 border-white/[0.08]',
    basico:        'bg-sky-500/15 text-sky-300 border-sky-500/30',
    intermediario: 'bg-violet-500/15 text-violet-300 border-violet-500/30',
    maximo:        'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/40',
};

// ─── Status de elegibilidade da nota (Fase 92 · DESEMP-08) ────────────────
// Labels TRAVADOS pelo usuário — nunca renderizar o slug cru (blocked/partial/
// official). 'official' não ganha badge (nota já é a oficial, sem ressalva).
// Fase 109 (SHOP-DES-01/02): Shopee virou fonte financeira elegível (margem
// placeholder=1) — deixou de ser o cenário 'blocked' (que agora só ocorre com
// vínculo em setor sem QUALQUER fonte financeira, ex.: Polos/Publicação).
const SCORE_STATUS_LABEL = {
    blocked: 'Sem fonte financeira',
    partial: 'Parcial',
    official: 'Oficial',
};
const SCORE_STATUS_BADGE_CLS = {
    blocked: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    partial: 'bg-amber-500/10 text-amber-200/70 border-amber-500/20',
};
const SCORE_STATUS_TOOLTIP = {
    blocked: 'Carteira sem nenhum vínculo com fonte financeira — nota não é oficial (aguarda régua de bônus).',
    partial: 'Nota parcial — parte dos componentes do cálculo ainda não está disponível para esta carteira.',
};

// Fase 90 (CART-07) — rótulos pt-BR nunca slug cru (regra sistêmica do
// projeto). Mesma lista de opções usada em Portfolio/Carteiras.jsx, aqui o
// filtro é view-only client-side (Fase 92 · SC3): nunca recalcula a nota,
// só decide quais linhas do ranking aparecem.
const CONTEXTO_OPTIONS = [
    { value: 'todos', label: 'Todos' },
    { value: 'performance', label: 'Mercado Livre' },
    { value: 'shopee', label: 'Shopee' },
];

function MedalBadge({ idx }) {
    if (idx === 0) return <span className="text-ecf-yellow font-display font-extrabold text-xl">🥇</span>;
    if (idx === 1) return <span className="text-white/60 font-display font-extrabold text-xl">🥈</span>;
    if (idx === 2) return <span className="text-orange-400 font-display font-extrabold text-xl">🥉</span>;
    return <span className="text-white/25 font-display font-bold text-base w-7 text-center">{idx + 1}</span>;
}

function SelectBox({ value, onChange, children, className = '' }) {
    return (
        <div className={cn('relative', className)}>
            <select
                value={value}
                onChange={e => onChange(e.target.value)}
                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 transition-all cursor-pointer"
            >
                {children}
            </select>
            <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}

function formatMesLabel(mes) {
    const [year, month] = mes.split('-');
    const meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return `${meses[parseInt(month, 10) - 1]} ${year}`;
}

// Formata "YYYY-MM-01" em "julho/2026" (pt-BR).
function mesExtensoDate(iso) {
    if (!iso) return '—';
    try {
        const [y, m] = String(iso).split('-');
        const d = new Date(Number(y), Number(m) - 1, 1);
        return d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    } catch {
        return String(iso);
    }
}

// ─── Fase 104 (UIP-01/UIP-03/UIP-04) — toggle de período/contexto de bônus ─
// Rótulos travados pelo usuário — nunca renderizar slug cru ('em_curso'/
// 'bonus_atual'/'closed_period'/'official' na tela).
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

// % com sinal + 1 casa; null → "—".
function formatPercent(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    const n = Number(v);
    return `${n >= 0 ? '+' : ''}${n.toFixed(1)}%`;
}

// Nota em escala 0-5 com 2 casas SEMPRE — "4,03", nunca "4" nem "4,1".
// Desde 2026-08-05 os componentes são médias de N lojas, então quase nunca
// caem em valor redondo; exibir com casas variáveis fazia a mesma tela
// mostrar "4", "4,1" e "4,03" lado a lado, e a conta parecia não fechar.
export function formatNota(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(2).replace('.', ',');
}

// Conta usada pra montar a nota final ("(3,83+3,61+4,65)/3"). Fallback pro
// "/ 5,00" antigo quando o backend não expõe o breakdown (snapshots antigos,
// users sem carteira).
function formatContaNota(pontos) {
    if (!pontos) return '/ 5,00';
    const pts = [pontos.nps, pontos.faturamento, pontos.margem].filter((v) => v != null);
    if (pts.length === 0) return '/ 5,00';
    return `(${pts.map(formatNota).join('+')})/${pts.length}`;
}

export default function PerformanceIndex({
    ranking = [],
    period = '30',       // preservado por compat com Polos (não usado no v2 de Consultoria)
    setor = 'consultoria',
    cargo = null,
    mes_fechado = null,   // 'YYYY-MM-01' | null — D-20
    mes,                  // Polos
    meses = [],           // Polos
    // Ajuste 2026-07-09 — filtro de mês
    mes_selecionado = null,      // 'YYYY-MM' — mês atualmente exibido
    mes_em_curso = true,          // true se mes_selecionado é o mês corrente
    meses_disponiveis = [],       // [{ value: 'YYYY-MM', label: 'julho/2026', em_curso: bool }]
    // Fase 92 (SC3) — filtro de auditoria por contexto. O backend (Plan 92-01)
    // só valida a whitelist e ecoa esta prop; a filtragem visual é client-side.
    contexto: contextoInicial = 'todos',
    // Fase 104 (UIP-02/UIP-03) — objeto periodo do MetricPeriodResolver
    // (shape completo, 14 chaves incl. is_closed/bonus_*) e bloco bonus
    // (competence_month/payment_month, null quando o mês está em curso).
    periodo = null,
    bonus = null,
    // Fase 106 (SC2) — true enquanto o warm sob-demanda do cache de desempenho
    // roda em background (mês fechado com ≥1 profissional ainda frio).
    aquecendo = false,
}) {
    const isPolos = setor === 'polos';

    // Usuário logado (para gate do botão "Configuração" admin-only).
    const { auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    // Cada setor tem rota própria: POLOS → publicacao.desempenho.index e
    // consultoria → performance.index.
    const applyFilter = (params) => {
        router.get(
            route(isPolos ? 'publicacao.desempenho.index' : 'performance.index'),
            params,
            { preserveState: true },
        );
    };

    // 2026-08-05 — o toggle "Em curso / Bônus atual / Mês fechado" saiu. Ele
    // trocava o período por um atalho implícito, e o usuário perdia de vista
    // QUAL mês estava vendo: clicar num segmento mexia na seleção de mês sem
    // dizer para onde. Agora o mês é escolhido só pelo dropdown ao lado, que
    // é explícito e já rotula o mês em curso.
    //
    // `?modo=bonus_atual` continua sendo aceito pelo backend (links salvos e
    // e-mails antigos apontam pra lá) — só não há mais botão que o gere.
    const modoUrl = isPolos ? null : new URLSearchParams(window.location.search).get('modo');
    const modoBonusAtual = modoUrl === 'bonus_atual';

    // 2026-08-07 — o mês escolhido aqui acompanha o clique na linha. Sem isso
    // /performance/{user} caía no default (mês em curso) e o usuário tinha que
    // reescolher no dropdown do Show a competência que acabara de selecionar.
    //
    // Só anexa quando NÃO é o mês corrente: `?mes=` do mês em curso resolveria
    // pelo ramo `YYYY-MM` do MetricPeriodResolver em vez do `current_month`
    // (mode=operational, baseline de janela parcial), trocando o modo da tela
    // sem o usuário ter pedido. No mês corrente o default já é o certo.
    const paramsDoMes = () => (mes_selecionado && !mes_em_curso ? { mes: mes_selecionado } : {});

    // Phase 46-03 — user selecionado abre o EvolucaoDrawer à direita
    const [userSelecionado, setUserSelecionado] = useState(null);

    // ESC fecha o drawer
    useEffect(() => {
        if (!userSelecionado) return;
        const onKey = (e) => { if (e.key === 'Escape') setUserSelecionado(null); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [userSelecionado]);

    // ── Fase 106 (SC2) — poll parcial enquanto o cache do mês fechado está
    // sendo aquecido em background (warm sob-demanda, Plan 106-02). Mesmo
    // padrão do Modo TV (RankingConsultoria não pollava antes), mas
    // condicionado a 'aquecendo' e com teto de tentativas pra não pollar
    // pra sempre caso o job de warm falhe/trave.
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
                router.reload({ only: ['ranking', 'aquecendo'], preserveScroll: true, preserveState: true });
            }
        }, 6000);
        return () => clearInterval(id);
    }, [aquecendo]);

    // Botão manual do aviso de teto esgotado — zera o contador e tenta de novo.
    const recarregarAquecendoManual = () => {
        tentativasAquecendoRef.current = 0;
        setPollEsgotado(false);
        router.reload({ only: ['ranking', 'aquecendo'], preserveScroll: true, preserveState: true });
    };

    // ── DESEMP-10 · separação sem_carteira / rankable ────────────────────
    const rankingElegivel = useMemo(
        () => ranking.filter(r => !r.sem_carteira),
        [ranking],
    );
    const semCarteira = useMemo(
        () => ranking.filter(r => r.sem_carteira),
        [ranking],
    );

    // Fase 92 (SC3) — select de contexto (Todos/Mercado Livre/Shopee), view-only.
    // Filtra só quais linhas aparecem, usando os metadados já presentes no
    // payload (vinculos_sem_fonte_financeira) — NUNCA recalcula nota_final.
    const [contexto, setContexto] = useState(contextoInicial ?? 'todos');
    const rankingFiltrado = useMemo(() => {
        if (contexto === 'shopee') {
            return rankingElegivel.filter(r => (r.vinculos_sem_fonte_financeira ?? 0) > 0);
        }
        if (contexto === 'performance') {
            return rankingElegivel.filter(r => (r.vinculos_sem_fonte_financeira ?? 0) === 0);
        }
        return rankingElegivel;
    }, [rankingElegivel, contexto]);

    // O drawer de evolução compara sempre contra o grupo elegível inteiro —
    // o filtro de contexto é só de visualização da tabela, não muda a mediana.
    const allRankingIds = rankingElegivel.map((r) => r.id);

    // Como calculamos? — collapsible
    const [howOpen, setHowOpen] = useState(false);

    // Publicações — dashboard executivo de TV (rota separada).
    if (isPolos) {
        return <PolosDashboard ranking={ranking} mes={mes} meses={meses} />;
    }

    // Consultoria — ranking v2 da Phase 74.
    return (
        <AppLayout title="Desempenho">
            <div className="space-y-5 max-w-[1200px]">
                {/* Header */}
                <div className="flex items-start justify-between flex-wrap gap-3">
                    <div className="flex items-start gap-3">
                        <span className="grid h-11 w-11 place-items-center rounded-xl bg-ecf-yellow/10 text-ecf-yellow shrink-0">
                            <Trophy size={20} />
                        </span>
                        <div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-white text-xl font-display font-extrabold leading-tight">
                                    Ranking Performance
                                </h1>
                                {mes_em_curso ? (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-amber-500/15 text-amber-300 border border-amber-500/30 tracking-wider">
                                        parcial · mês em andamento
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 tracking-wider">
                                        Mês fechado
                                    </span>
                                )}
                            </div>
                            <p className="text-white/50 text-sm mt-0.5">
                                {mes_em_curso
                                    ? 'Ranking parcial — a consolidação mensal fecha dia 1 do mês seguinte.'
                                    : 'Ranking consolidado — dados do mês fechado (usados na régua de bônus).'}
                            </p>
                            {/* Fase 104 (UIP-03) — quando a URL veio com ?modo=bonus_atual,
                                deixa explícito qual competência está sendo avaliada e
                                quando ela é paga. */}
                            {modoBonusAtual && bonus && (
                                <p className="text-ecf-yellow/80 text-xs mt-1 font-medium">
                                    Competência {formatCompetencia(bonus.competence_month)} · pago em {formatMesSolo(bonus.payment_month)}
                                </p>
                            )}
                            {/* Subtítulo discreto de auditoria — janela atual vs baseline. */}
                            {periodo?.current_start && periodo?.baseline_start && (
                                <p className="text-white/30 text-[11px] mt-1">
                                    {formatRangeCurto(periodo.current_start)}–{formatRangeCurto(periodo.current_end)}
                                    {' vs '}
                                    {formatRangeCurto(periodo.baseline_start)}–{formatRangeCurto(periodo.baseline_end)}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Filtro de mês — único controle de período da tela desde
                            2026-08-05 (o toggle de contexto foi removido). */}
                        {Array.isArray(meses_disponiveis) && meses_disponiveis.length > 0 && (
                            <select
                                value={mes_selecionado ?? ''}
                                onChange={(e) => applyFilter({ setor, cargo, mes: e.target.value })}
                                title="Selecionar mês do ranking"
                                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer capitalize"
                            >
                                {meses_disponiveis.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}{m.em_curso ? ' (em curso)' : ''}
                                    </option>
                                ))}
                            </select>
                        )}
                        {/* Fase 92 (SC3) — filtro de auditoria por contexto, view-only:
                            muda só quais linhas aparecem, nunca recalcula nota. */}
                        <select
                            value={contexto}
                            onChange={(e) => setContexto(e.target.value)}
                            title="Filtrar visualização por contexto de serviço (não recalcula a nota)"
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
                        >
                            {CONTEXTO_OPTIONS.map(o => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                        {/* Botão admin-only: acesso à configuração das faixas de bônus. */}
                        {isAdmin && (
                            <Link
                                href={route('desempenho.configuracao.index')}
                                title="Configurar régua de bonificação (admin)"
                                className="flex items-center gap-1.5 h-9 px-3 rounded-xl border border-white/[0.08] text-white/60 hover:text-white hover:border-white/25 hover:bg-white/[0.03] transition-colors text-[13px]"
                            >
                                <Settings size={14} />
                                <span className="hidden sm:inline">Configurar régua</span>
                            </Link>
                        )}
                        {/* Filtro por cargo — Geral/Analista/Estrategista */}
                        <div className="flex rounded-xl border border-white/[0.08] overflow-hidden">
                            {[
                                { label: 'Geral',         value: null },
                                { label: 'Analistas',     value: 'analista' },
                                { label: 'Estrategistas', value: 'estrategista' },
                            ].map((opt, i, arr) => (
                                <div key={opt.value ?? 'geral'} className="flex">
                                    <button
                                        onClick={() => applyFilter(
                                            // Trocar de cargo preserva o mês selecionado — o mês
                                            // é escolhido só no dropdown, nunca por efeito colateral
                                            // de outro filtro.
                                            opt.value
                                                ? { setor: 'consultoria', cargo: opt.value, mes: mes_selecionado }
                                                : { setor: 'consultoria', mes: mes_selecionado }
                                        )}
                                        className={cn(
                                            'px-3 h-9 text-[13px] font-medium transition-colors',
                                            cargo === opt.value
                                                ? 'bg-ecf-yellow/[0.12] text-ecf-yellow'
                                                : 'text-white/50 hover:text-white/80 hover:bg-white/[0.04]'
                                        )}
                                    >
                                        {opt.label}
                                    </button>
                                    {i < arr.length - 1 && <div className="w-px bg-white/[0.08]" />}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Bloco "Como calculamos?" — collapsible */}
                <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] overflow-hidden">
                    <button
                        type="button"
                        onClick={() => setHowOpen(v => !v)}
                        className="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-white/[0.03] transition-colors"
                    >
                        <span className="flex items-center gap-2 text-white/70 text-sm">
                            <Info size={14} className="text-ecf-yellow/70" />
                            Como calculamos a nota final?
                        </span>
                        <ChevronDown
                            size={16}
                            className={cn(
                                'text-white/40 transition-transform',
                                howOpen && 'rotate-180'
                            )}
                        />
                    </button>
                    {howOpen && (
                        <div className="px-4 pb-4 pt-1 border-t border-white/[0.05] space-y-2 text-[13px] text-white/60 leading-relaxed">
                            <p>
                                <strong className="text-white/80">Nota final = média direta</strong> dos componentes disponíveis: NPS médio,
                                variação de faturamento vs mês anterior, variação de margem de contribuição vs mês anterior.
                                Absenteísmo em <em>standby</em> — não participa desta versão.
                            </p>
                            <p>
                                A <strong className="text-white/80">faixa de bônus</strong> é atribuída em ordem crescente
                                (Sem bônus → Básico → Intermediário → Máximo) a partir da nota final. 2 meses consecutivos em Intermediário
                                promovem automaticamente para Máximo.
                            </p>
                            <p className="text-white/50 text-xs pt-1">
                                As faixas são configuráveis por administradores em{' '}
                                <Link href="/desempenho/configuracao" className="text-ecf-yellow hover:underline">
                                    /desempenho/configuracao
                                </Link>{' '}
                                · veja o detalhamento em{' '}
                                <Link href="/manual/desempenho-bonificacao" className="text-ecf-yellow hover:underline">
                                    /manual/desempenho-bonificacao
                                </Link>.
                            </p>
                        </div>
                    )}
                </div>

                {/* Fase 106 (SC2) — aviso quando o poll de aquecimento atingiu o teto
                    (~2min) sem o mês terminar de esquentar; oferece recarga manual. */}
                {pollEsgotado && aquecendo && (
                    <div className="rounded-2xl border border-amber-500/20 bg-amber-500/[0.04] px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
                        <div className="flex items-center gap-2 text-amber-200/80 text-sm">
                            <Clock size={14} className="text-amber-300/70 shrink-0" />
                            Demorando mais que o esperado para calcular este mês.
                        </div>
                        <button
                            type="button"
                            onClick={recarregarAquecendoManual}
                            className="shrink-0 h-8 px-3 rounded-lg border border-amber-500/30 text-amber-200 text-[12px] font-semibold hover:bg-amber-500/10 transition-colors"
                        >
                            Recarregar
                        </button>
                    </div>
                )}

                {rankingFiltrado.length === 0 ? (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-12 text-center">
                        <Trophy size={32} className="mx-auto mb-3 text-white/20" />
                        <p className="text-white/40 text-sm">
                            Nenhum profissional com dados de desempenho para o período.
                        </p>
                    </div>
                ) : (
                    <RankingConsultoria ranking={rankingFiltrado} onSelectUser={setUserSelecionado} />
                )}

                {/* DESEMP-10 · Bloco de transparência — excluídos por falta de carteira */}
                {semCarteira.length > 0 && (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.015] p-4">
                        <div className="flex items-center gap-2 mb-3">
                            <UserX size={14} className="text-white/40" />
                            <span className="text-white/60 text-xs font-semibold uppercase tracking-wider">
                                Excluídos do ranking · sem carteira no período
                            </span>
                            <span className="text-white/30 text-xs">({semCarteira.length})</span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {semCarteira.map(u => (
                                <span
                                    key={u.id}
                                    className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/[0.03] border border-white/[0.08] text-white/60 text-xs"
                                    title={u.motivo ?? 'Sem carteira no período'}
                                >
                                    <span className="w-1.5 h-1.5 rounded-full bg-white/30" />
                                    {u.name}
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Phase 46-03 — Drawer de evolução individual */}
            {userSelecionado && (
                <EvolucaoDrawer
                    rankingItem={userSelecionado}
                    allRankingIds={allRankingIds}
                    onClose={() => setUserSelecionado(null)}
                />
            )}
        </AppLayout>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// RankingConsultoria — Tabela do ranking v2 (Phase 74 · Plan 74-06)
//
// Colunas: Posição · Nome+cargo · Nota final + delta · Faixa · Δ mês
//          NPS · Var Fat · Var Margem · Empresas · Detalhes
//
// Sem menções às métricas v1 (crescimento_ajustado_pct, atingimento_meta_pct,
// recuperacao, execucao_ads) — big bang DESEMP-14.
// ═══════════════════════════════════════════════════════════════════════
function RankingConsultoria({ ranking, onSelectUser }) {
    return (
        <div className="rounded-2xl border border-white/[0.06] bg-ecf-card overflow-hidden">
            <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_6rem_7.5rem_5rem_4.5rem_6.5rem_6rem_5rem_2rem] gap-2 px-5 py-3 border-b border-white/[0.06] text-white/40 text-[11px] font-semibold uppercase tracking-wide">
                <span>#</span>
                <span>Nome</span>
                <span className="text-right" title="Nota final do mês (média direta dos parâmetros disponíveis, escala 0-5).">Nota</span>
                <span title="Faixa de bônus com base na nota final do mês.">Faixa</span>
                <span className="text-right" title="Delta vs mês passado fechado.">Δ mês</span>
                <span className="text-right" title="Pontos de NPS (escala 1-5) — média dos pontos das lojas da carteira.">NPS</span>
                <span className="text-right" title="Pontos de faturamento (escala 1-5) — média dos pontos das lojas da carteira. A régua é aplicada loja a loja; a % de variação fica no tooltip de cada valor.">Faturamento</span>
                <span className="text-right" title="Pontos de margem (escala 1-5) — média dos pontos das lojas da carteira. Loja Shopee não entra (a plataforma não fornece CMV).">Margem</span>
                <span className="text-right" title="Empresas usadas no cálculo / total na carteira. As não usadas são empresas novas (menos de 2 meses) ou sem dados do mês anterior para comparar.">Empresas</span>
                <span />
            </div>

            <div className="divide-y divide-white/[0.04]">
                {ranking.map((u, idx) => {
                    // Fase 106 (SC2) — linha "fria": mês fechado sem cache pronto,
                    // warm rodando em background (Plan 106-02). Nota/faixa/deltas
                    // ficam em estado transitório "calculando…" até o poll atualizar.
                    const calculando = u.calculando === true;
                    const faixaSlug = u.faixa_bonus ?? 'sem_bonus';
                    const faixaCls = FAIXA_BADGE_CLS[faixaSlug] ?? FAIXA_BADGE_CLS.sem_bonus;
                    const faixaLbl = FAIXA_LABEL[faixaSlug] ?? faixaSlug;
                    const nota = formatNota(u.nota_final);

                    return (
                        <div
                            key={u.id}
                            className={cn(
                                'grid grid-cols-[2.5rem_minmax(0,1fr)_6rem_7.5rem_5rem_4.5rem_6.5rem_6rem_5rem_2rem] gap-2 px-5 py-3 items-center transition-colors',
                                idx === 0 && 'bg-ecf-yellow/[0.03]',
                                calculando ? 'cursor-default' : 'hover:bg-white/[0.04] cursor-pointer',
                            )}
                            onClick={() => { if (!calculando) router.visit(route('performance.show', { user: u.id, ...paramsDoMes() })); }}
                        >
                            {/* Posição */}
                            <div className="flex items-center justify-center">
                                {idx < 3
                                    ? <MedalBadge idx={idx} />
                                    : <span className="text-white/40 font-semibold text-[12px] tabular-nums">{u.posicao ?? idx + 1}</span>}
                            </div>

                            {/* Nome + cargo */}
                            <div className="min-w-0">
                                <p className="text-white font-semibold text-[13px] truncate">{u.name}</p>
                                <p className="text-white/40 text-[11px]">{u.cargo_label ?? '—'}</p>
                            </div>

                            {/* Nota final + conta que gerou (ex: "(3+5+4)/3") ou badge de
                                status (Fase 92) quando a nota não é 100% oficial. */}
                            <div className="text-right">
                                {calculando ? (
                                    <span
                                        className="text-white/40 text-[12px] font-medium animate-pulse tabular-nums"
                                        title="Calculando a nota deste profissional em segundo plano — a página atualiza sozinha."
                                    >
                                        calculando…
                                    </span>
                                ) : (
                                    <>
                                        <span className="text-white font-display font-extrabold text-[16px] tabular-nums">{nota}</span>
                                        {u.score_status && u.score_status !== 'official' ? (
                                            <span
                                                className={cn(
                                                    'block mt-0.5 text-right text-[8.5px] leading-tight font-semibold px-1.5 py-0.5 rounded-md border',
                                                    SCORE_STATUS_BADGE_CLS[u.score_status] ?? SCORE_STATUS_BADGE_CLS.partial,
                                                )}
                                                title={SCORE_STATUS_TOOLTIP[u.score_status] ?? ''}
                                            >
                                                {SCORE_STATUS_LABEL[u.score_status] ?? u.score_status}
                                            </span>
                                        ) : (
                                            <span
                                                className="text-white/30 text-[10px] block leading-none mt-0.5 tabular-nums"
                                                title="Média dos pontos NPS, faturamento e margem (régua 1-5)"
                                            >
                                                {formatContaNota(u.pontos_componentes)}
                                            </span>
                                        )}
                                    </>
                                )}
                            </div>

                            {/* Faixa + promovida — omitida enquanto a linha está calculando */}
                            <div className="flex items-center gap-1.5 flex-wrap">
                                {calculando ? (
                                    <span className="text-white/20 text-[11px]">—</span>
                                ) : (
                                    <>
                                        <span className={cn(
                                            'inline-flex items-center text-[10px] font-semibold px-2 py-0.5 rounded-full border',
                                            faixaCls,
                                        )}>
                                            {faixaLbl}
                                        </span>
                                        {u.faixa_promovida && (
                                            <span
                                                className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full border bg-emerald-500/15 text-emerald-300 border-emerald-500/30"
                                                title="Promovida por 2 meses consecutivos em Intermediário"
                                            >
                                                <Sparkles size={9} />
                                                PROMOVIDA
                                            </span>
                                        )}
                                    </>
                                )}
                            </div>

                            {/* Delta vs mês passado — sempre '—' enquanto calculando */}
                            <div className="text-right">
                                <DeltaMes delta={calculando ? null : u.delta_vs_mes_passado} />
                            </div>

                            {/* NPS — pontos (média dos pontos por loja) */}
                            <div className="text-right">
                                <PontosToneCell
                                    pontos={calculando ? null : u.pontos_componentes?.nps}
                                    titulo="Média dos pontos de NPS das lojas da carteira"
                                />
                            </div>

                            {/* Faturamento — pontos */}
                            <div className="text-right">
                                <PontosToneCell
                                    pontos={calculando ? null : u.pontos_componentes?.faturamento}
                                    pct={u.componentes?.var_faturamento_pct}
                                    titulo="Média dos pontos de faturamento das lojas da carteira"
                                />
                            </div>

                            {/* Margem — pontos. Carteira 100% Shopee não tem margem
                                (a plataforma não fornece CMV), então a coluna fica "—"
                                com explicação no tooltip, em vez de número inventado. */}
                            <div className="text-right">
                                <PontosToneCell
                                    pontos={calculando ? null : u.pontos_componentes?.margem}
                                    pct={u.componentes?.var_margem_pp}
                                    sufixoPct=" p.p."
                                    titulo={
                                        !calculando && u.pontos_componentes?.margem == null
                                            ? 'Sem margem na carteira — a Shopee não fornece CMV e loja sem baseline não entra'
                                            : 'Média dos pontos de margem das lojas da carteira'
                                    }
                                />
                            </div>

                            {/* Empresas — tooltip com os metadados de elegibilidade (Fase 92 · SC2):
                                empresas únicas, vínculos de serviço, vínculos sem fonte financeira. */}
                            <div
                                className="text-right text-white/70 tabular-nums text-[12px]"
                                title={`Empresas únicas na carteira: ${u.empresas_unicas ?? 0} · Vínculos de serviço: ${u.vinculos_servico ?? 0} · Vínculos sem fonte financeira: ${u.vinculos_sem_fonte_financeira ?? 0}`}
                            >
                                {u.empresas_com_baseline ?? 0}/{u.empresas_carteira ?? 0}
                            </div>

                            {/* Chevron visual apenas — a linha inteira é clicável e leva
                                pra performance.show (detalhes da nota). Ajuste 2026-07-13. */}
                            <div className="flex items-center justify-end">
                                <ChevronRight size={14} className="text-white/30" />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// Célula de delta mensal (nota_final vs mês passado). null = "—".
function DeltaMes({ delta }) {
    if (delta == null || Number.isNaN(Number(delta))) {
        return <span className="text-white/20 font-bold tabular-nums text-[11px]">—</span>;
    }
    const n = Number(delta);
    const cls = n > 0.05 ? 'text-emerald-300' : n < -0.05 ? 'text-rose-300' : 'text-white/50';
    const arrow = n > 0.05 ? '↑' : n < -0.05 ? '↓' : '—';
    const sign = n > 0 ? '+' : '';
    return (
        <span className={cn('inline-flex items-center gap-0.5 font-semibold tabular-nums text-[11px]', cls)}>
            <span aria-hidden="true">{arrow}</span>
            {sign}{n.toFixed(2)}
        </span>
    );
}

// Célula de % com tom (verde/vermelho/neutro).
function PctToneCell({ value }) {
    if (value == null || Number.isNaN(Number(value))) {
        return <span className="text-white/20 font-bold">—</span>;
    }
    const n = Number(value);
    const cls = n > 0.5 ? 'text-emerald-300' : n < -0.5 ? 'text-rose-300' : 'text-white/60';
    const sign = n > 0 ? '+' : '';
    return (
        <span className={cn('font-semibold tabular-nums text-[12px]', cls)}>
            {sign}{n.toFixed(1)}%
        </span>
    );
}

// Célula de PONTOS (escala 1-5) das colunas NPS / Faturamento / Margem.
//
// 2026-08-05 — o ranking mostrava a % de variação nessas colunas, enquanto a
// nota já vinha dos PONTOS. Isso deixava a tela impossível de conferir de
// cabeça: o topo dizia "+34,1%" e a nota final somava "5,00" sem que a ligação
// entre os dois aparecesse em lugar nenhum. Agora a coluna mostra o mesmo
// número que entra na conta, igual à tabela por empresa de `/performance/{id}`.
//
// A % continua acessível no tooltip — deixou de ser protagonista, não sumiu.
// Tom pela faixa da régua: 1-2 é queda, 3 é estável, 4-5 é crescimento.
function PontosToneCell({ pontos, pct, sufixoPct = '%', titulo }) {
    if (pontos == null || Number.isNaN(Number(pontos))) {
        return <span className="text-white/20 font-bold" title={titulo}>—</span>;
    }

    const n   = Number(pontos);
    const cls = n >= 4 ? 'text-emerald-300' : n <= 2 ? 'text-rose-300' : 'text-white/60';

    // Tooltip: a % agregada que a régua enxergava, quando existir. Serve de
    // pista de auditoria — nunca é o número que decide a nota.
    const dica = [
        titulo,
        pct != null && !Number.isNaN(Number(pct))
            ? `Variação agregada: ${Number(pct) > 0 ? '+' : ''}${Number(pct).toFixed(1)}${sufixoPct}`
            : null,
    ].filter(Boolean).join(' · ');

    return (
        <span className={cn('font-semibold tabular-nums text-[12px]', cls)} title={dica || undefined}>
            {formatNota(n)}
        </span>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Phase 46-03 — EvolucaoDrawer
// Drawer lateral direito com gráfico Recharts da curva individual de score
// comparada à mediana do grupo. Faz fetch on-open de:
//   1. GET /api/performance/{id}/evolucao?period=30 do user selecionado
//   2. Promise.all dos demais users do ranking (para calcular mediana por data)
// ═══════════════════════════════════════════════════════════════════════
function EvolucaoDrawer({ rankingItem, allRankingIds, onClose }) {
    const [serie, setSerie]                       = useState(null);
    const [grupoMedianoPorData, setGrupoMediano]  = useState(null);
    const [loading, setLoading]                   = useState(true);

    useEffect(() => {
        if (!rankingItem) return;
        setLoading(true);
        setSerie(null);
        setGrupoMediano(null);

        const fetchOpts = {
            headers:     { 'Accept': 'application/json' },
            credentials: 'same-origin',
        };

        // 1. Curva do user selecionado
        const fetchUser = fetch(route('performance.evolucao', rankingItem.id) + '?period=30', fetchOpts)
            .then((r) => r.json())
            .catch(() => ({ serie: [] }));

        // 2. Curva dos demais users — em paralelo, ignora falhas individuais
        const fetchGrupo = Promise.all(
            allRankingIds
                .filter((id) => id !== rankingItem.id)
                .map((id) =>
                    fetch(route('performance.evolucao', id) + '?period=30', fetchOpts)
                        .then((r) => r.json())
                        .catch(() => ({ serie: [] }))
                )
        );

        Promise.all([fetchUser, fetchGrupo]).then(([userData, grupoData]) => {
            setSerie(userData.serie ?? []);

            // Mediana por data (calculada client-side a partir das séries do grupo)
            const porData = {};
            grupoData.forEach((d) => {
                (d.serie ?? []).forEach((p) => {
                    if (!porData[p.date]) porData[p.date] = [];
                    porData[p.date].push(p.score);
                });
            });
            const mediana = {};
            Object.keys(porData).forEach((date) => {
                const sorted = [...porData[date]].sort((a, b) => a - b);
                const mid    = Math.floor(sorted.length / 2);
                mediana[date] = sorted.length % 2 === 0
                    ? (sorted[mid - 1] + sorted[mid]) / 2
                    : sorted[mid];
            });
            setGrupoMediano(mediana);
            setLoading(false);
        });
    }, [rankingItem, allRankingIds]);

    // Merge serie user + mediana → formato para Recharts
    const chartData = (serie ?? []).map((p) => ({
        date:    p.date,
        score:   p.score,
        mediana: grupoMedianoPorData?.[p.date] ?? null,
    }));

    const delta = rankingItem.delta_vs_ontem;
    // Phase 74 D-05 · o payload v2 do controller passa `nota_final` (0-5).
    // Preservamos fallback para `score` (0-100) caso venha de contexto legacy.
    const notaAtual = rankingItem.nota_final != null
        ? formatNota(rankingItem.nota_final)
        : rankingItem.score != null
            ? Number(rankingItem.score).toFixed(0)
            : '—';

    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <aside className="relative h-full w-full max-w-2xl overflow-y-auto border-l border-white/[0.1] bg-ecf-bg shadow-2xl">
                {/* Header sticky */}
                <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-white/[0.08] bg-ecf-bg/95 px-5 py-4 backdrop-blur">
                    <div>
                        <h2 className="text-white font-display font-extrabold text-lg leading-tight">{rankingItem.name}</h2>
                        <p className="text-white/40 text-xs">
                            {rankingItem.cargo_label} · Nota final:{' '}
                            <span className="text-white/70 font-semibold tabular-nums">{notaAtual}</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-white/50 hover:bg-white/[0.06] hover:text-white"
                        aria-label="Fechar"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* KPI cards */}
                <div className="grid grid-cols-2 gap-2 px-5 py-4 border-b border-white/[0.06]">
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Nota atual</p>
                        <p className="text-ecf-yellow font-display font-extrabold text-2xl mt-0.5 tabular-nums">{notaAtual}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">vs ontem</p>
                        <div className="mt-1 flex items-center gap-2">
                            {delta === null || delta === undefined ? (
                                <span className="text-white/30 font-display font-extrabold text-2xl">—</span>
                            ) : (
                                <>
                                    {delta > 1 && <TrendingUp size={18} className="text-emerald-300" />}
                                    {delta < -1 && <TrendingUp size={18} className="text-red-300 rotate-180" />}
                                    <span className={cn(
                                        'font-display font-extrabold text-2xl tabular-nums',
                                        delta > 1 ? 'text-emerald-300' : delta < -1 ? 'text-red-300' : 'text-white/60'
                                    )}>
                                        {delta > 0 ? '+' : ''}{delta.toFixed(1)}
                                    </span>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                {/* Gráfico */}
                <div className="px-5 py-4">
                    <h3 className="text-white/60 text-xs uppercase tracking-wider mb-3">Evolução — últimos 30 dias</h3>

                    {loading && (
                        <div className="flex items-center gap-2 py-12 justify-center">
                            <div className="h-3 w-3 animate-pulse rounded-full bg-white/30" />
                            <p className="text-white/40 text-sm">Carregando histórico...</p>
                        </div>
                    )}

                    {!loading && chartData.length === 0 && (
                        <p className="text-white/40 text-sm py-12 text-center">
                            Sem histórico ainda — snapshots começam a partir do próximo 13:30 BRT.
                        </p>
                    )}

                    {!loading && chartData.length > 0 && (
                        <ResponsiveContainer width="100%" height={280}>
                            <LineChart data={chartData} margin={{ top: 5, right: 12, left: -8, bottom: 5 }}>
                                <XAxis dataKey="date" stroke="#666" fontSize={10} tickLine={false} axisLine={{ stroke: 'rgba(255,255,255,0.08)' }} />
                                <YAxis domain={[0, 100]} stroke="#666" fontSize={10} tickLine={false} axisLine={{ stroke: 'rgba(255,255,255,0.08)' }} />
                                <Tooltip
                                    contentStyle={{
                                        background:   '#0f1116',
                                        border:       '1px solid rgba(255,255,255,0.1)',
                                        borderRadius: '8px',
                                        fontSize:     '12px',
                                    }}
                                    labelStyle={{ color: 'rgba(255,255,255,0.6)' }}
                                />
                                <Legend wrapperStyle={{ fontSize: '11px', color: 'rgba(255,255,255,0.5)' }} />
                                <Line
                                    type="monotone"
                                    dataKey="score"
                                    stroke="#ffe600"
                                    strokeWidth={2}
                                    dot={false}
                                    name={rankingItem.name}
                                />
                                <Line
                                    type="monotone"
                                    dataKey="mediana"
                                    stroke="rgba(255,255,255,0.4)"
                                    strokeDasharray="4 4"
                                    strokeWidth={1.5}
                                    dot={false}
                                    name="Mediana do grupo"
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    )}
                </div>
            </aside>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Publicações — Dashboard executivo de TV
// Reorganiza o antigo ranking-tabela em blocos: KPIs de resumo → destaque do
// líder + produção por publicador → ranking moderno → ticker de destaques.
// Pensado para ficar exposto numa TV o dia todo: leitura em segundos, animações
// leves (CSS transition + rAF, sem lib JS) e auto-refresh só da prop 'ranking'.
// Sem fotos — a identidade vem de avatar com iniciais + medalhas/troféu.
// Refino 260708 (nível "torre executiva"): fundo sutil, cards com linha de marca
// + glow, count-up nos números, barras com gradiente/brilho e ranking em cartões.
// ═══════════════════════════════════════════════════════════════════════

// Superfície bg-ecf-card-2/60 espelha o HeroKpi (mesma página) — cards consistentes
// no dark e superfície branca sólida no modo claro (light.css mapeia ecf-card-2 → #fff).
// A sombra suave (drop + inset-highlight) dá profundidade sem "flutuar" demais na TV.
const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card-2/60 shadow-[0_1px_0_0_rgba(255,255,255,0.04)_inset,0_24px_48px_-32px_rgba(0,0,0,0.7)] transition-[box-shadow,border-color] duration-300';

// Paleta de accents da página — cada bloco/KPI ganha uma identidade cromática
// dentro do design system (amarelo da marca + apoios frios/quentes já usados).
const ACCENTS = {
    yellow: '#ffe600',
    blue:   '#60a5fa',
    green:  '#22c55e',
    red:    '#ef4444',
    orange: '#f97316',
    violet: '#a78bfa',
};

// Respeita "reduzir movimento" do SO — desliga count-up e sheen em quem pediu.
function usePrefersReducedMotion() {
    const [reduz, setReduz] = useState(false);
    useEffect(() => {
        const mq = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        if (!mq) return;
        setReduz(mq.matches);
        const on = (e) => setReduz(e.matches);
        mq.addEventListener?.('change', on);
        return () => mq.removeEventListener?.('change', on);
    }, []);
    return reduz;
}

// Conta 0 → alvo com easeOutCubic (~900ms). Leve: um rAF só, cancela na saída.
// `enabled=false` (reduced-motion) entrega o valor final de imediato.
function useCountUp(target, { duration = 900, enabled = true } = {}) {
    const to = Number(target) || 0;
    const [val, setVal] = useState(enabled ? 0 : to);
    useEffect(() => {
        if (!enabled) { setVal(to); return; }
        let raf;
        const t0 = performance.now();
        const tick = (now) => {
            const p = Math.min((now - t0) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 3);
            setVal(to * eased);
            if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [to, enabled, duration]);
    return val;
}

// Número animado — usa o formatador da página (fmtInt/fmtScore/fmtPct1) sobre o
// valor interpolado. Fora do modo reduzido, o pai passa `animar` já resolvido.
function AnimatedNumber({ value, format = fmtInt, animar = true, duration = 900 }) {
    const v = useCountUp(value, { enabled: animar, duration });
    return <>{format(v)}</>;
}

// Mini gráfico de barras (SVG) — perfil real de uma série (sem eixos, decorativo-
// informativo). Usado no KPI "Produzido" pra mostrar a distribuição da equipe.
function Sparkline({ data = [], cor = '#ffe600', width = 96, height = 24 }) {
    if (!data.length) return null;
    const max = Math.max(...data, 1);
    const gap = 2;
    const bw  = (width - gap * (data.length - 1)) / data.length;
    return (
        <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true" className="overflow-visible">
            {data.map((d, i) => {
                const h = Math.max((d / max) * height, 1.5);
                return (
                    <rect key={i} x={i * (bw + gap)} y={height - h} width={bw} height={h} rx={Math.min(bw / 2, 2)}
                          fill={cor} opacity={0.35 + 0.5 * (d / max)} />
                );
            })}
        </svg>
    );
}

// Cartão de seção padronizado: superfície CARD + linha colorida superior (marca)
// + glow de canto opcional. Concentra o "dar vida" pedido (linha, gradiente, sombra)
// num só lugar, mantendo consistência entre Líder / Produção / Ranking / Ticker.
function SectionCard({ accent = ACCENTS.yellow, glow = false, className, children, ...rest }) {
    return (
        <div className={cn(CARD, 'animate-fade-in', className)} {...rest}>
            {/* linha colorida superior — identidade visual do bloco */}
            <span aria-hidden="true" className="absolute inset-x-0 top-0 h-[2px] opacity-80"
                  style={{ background: `linear-gradient(90deg, transparent, ${accent}, transparent)` }} />
            {glow && (
                <span aria-hidden="true"
                      className="pointer-events-none absolute -top-20 left-1/2 h-40 w-72 -translate-x-1/2 rounded-full blur-3xl"
                      style={{ background: `${accent}14` }} />
            )}
            {children}
        </div>
    );
}

// Fundo da página: grid quase invisível (com máscara radial pra sumir nas bordas)
// + brilho amarelo suave no topo. Elegante no dark; inócuo no modo claro (branco
// sobre branco). Fica atrás do conteúdo via -z-10 dentro do container relative.
function DashboardBackdrop() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
            <div
                className="absolute inset-0 opacity-60"
                style={{
                    backgroundImage: 'linear-gradient(rgba(255,255,255,0.022) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.022) 1px, transparent 1px)',
                    backgroundSize: '46px 46px',
                    maskImage: 'radial-gradient(ellipse at 50% 0%, #000 0%, transparent 72%)',
                    WebkitMaskImage: 'radial-gradient(ellipse at 50% 0%, #000 0%, transparent 72%)',
                }}
            />
            <div
                className="absolute -top-28 left-1/2 h-72 w-[54rem] -translate-x-1/2 rounded-full blur-3xl"
                style={{ background: 'radial-gradient(closest-side, rgba(255,230,0,0.07), transparent)' }}
            />
        </div>
    );
}

// Faixas de cor do score (espelha a lógica do antigo ScoreTag).
const SCORE_BANDS = [
    { min: 75, cor: '#22c55e' },
    { min: 50, cor: '#ffe600' },
    { min: 30, cor: '#f97316' },
    { min: 0,  cor: '#ef4444' },
];
const bandaScore = (v) => SCORE_BANDS.find((b) => (v ?? 0) >= b.min) ?? SCORE_BANDS[SCORE_BANDS.length - 1];

const STATUS_PILL = {
    'Acima da meta':  { cor: '#22c55e', label: 'Acima da meta' },
    'No alvo':        { cor: '#ffe600', label: 'No alvo' },
    'Abaixo da meta': { cor: '#ef4444', label: 'Abaixo da meta' },
};

const fmtScore = (n) => Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const fmtInt   = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const fmtPct1  = (n) => `${Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const fmtNota  = (n) => n == null ? '—' : Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const primeiroNome = (nome = '') => (nome.trim().split(/\s+/)[0] || '—');

// Plano de Metas do Time de Publicação — faixas de bônus (mesma régua do Meu Painel).
const FAIXA = {
    sem_bonus:     { label: 'Sem bônus',           cor: '#6b7280' },
    base:          { label: 'Bônus base',          cor: '#38bdf8' },
    intermediario: { label: 'Bônus intermediário', cor: '#a78bfa' },
    maximo:        { label: 'Bônus máximo',        cor: '#10b981' },
};
const faixaCor = (f) => (FAIXA[f] ?? FAIXA.sem_bonus).cor;

function FaixaBadge({ faixa, size = 'md' }) {
    const f = FAIXA[faixa] ?? FAIXA.sem_bonus;
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-semibold',
            size === 'sm' ? 'px-2 py-0.5 text-[10px]' : 'px-2.5 py-1 text-[11px]')}
              style={{ background: `${f.cor}1f`, color: f.cor }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: f.cor }} />
            {f.label}
        </span>
    );
}

// Célula de Nota (0-5) do ranking: barra por faixa + número na cor da faixa.
function NotaCell({ nota, faixa, score100, mounted, delay = 0 }) {
    const cor = faixaCor(faixa);
    const w = mounted ? Math.min(score100 ?? 0, 100) : 0;
    return (
        <div className="flex items-center justify-end gap-2.5">
            <div className="relative h-2 w-14 overflow-hidden rounded-full bg-white/[0.08]">
                <div className="h-full rounded-full transition-[width] duration-[900ms] ease-out"
                     style={{ width: `${w}%`, background: `linear-gradient(90deg, ${cor}aa, ${cor})`, boxShadow: `0 0 10px ${cor}66`, transitionDelay: `${delay}ms` }} />
            </div>
            <span className="w-12 text-right font-display text-[16px] font-extrabold leading-none tabular-nums" style={{ color: cor }}>
                {fmtNota(nota)}
            </span>
        </div>
    );
}

function iniciais(nome = '') {
    const p = nome.trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return ((p[0][0] ?? '') + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
}

// Anima valores 0 → alvo na montagem (mesmo padrão de RankingProgresso).
function useMounted() {
    const [m, setM] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setM(true));
        return () => cancelAnimationFrame(id);
    }, []);
    return m;
}

// Avatar do publicador. Se houver foto (src), renderiza a imagem; senão cai nas
// iniciais. Líder ganha destaque amarelo (anel/glow na foto, gradiente nas iniciais).
// A URL da foto vem de users.avatar_url (upload, foto do Google ou URL externa).
function Avatar({ nome, src, size = 40, destaque = false }) {
    const [erro, setErro] = useState(false);
    const usaFoto = Boolean(src) && !erro;
    return (
        <span
            aria-hidden="true"
            className={cn('inline-grid shrink-0 select-none place-items-center overflow-hidden rounded-full font-display font-extrabold',
                usaFoto
                    // Foto: só o anel amarelo quando destaque.
                    ? (destaque ? 'ring-2 ring-ecf-yellow/60' : '')
                    // Iniciais: não-destaque usa classes de opacidade (adaptam ao tema claro); líder usa o amarelo da marca.
                    : (destaque ? 'text-black' : 'border border-white/10 bg-white/[0.07] text-white/80'))}
            style={{
                width: size, height: size, fontSize: Math.round(size * 0.36),
                ...(destaque ? { boxShadow: '0 0 18px rgba(255,230,0,0.35)' } : null),
                ...(!usaFoto && destaque ? { background: 'linear-gradient(135deg, #ffe600, #f5d400)' } : null),
            }}
        >
            {usaFoto
                ? <img src={src} alt="" loading="lazy" className="h-full w-full object-cover" onError={() => setErro(true)} />
                : iniciais(nome)}
        </span>
    );
}

// Medalha para o Top 3, número simples do 4º em diante.
const MEDALHA = {
    0: 'linear-gradient(135deg, #fde047, #eab308)',
    1: 'linear-gradient(135deg, #e5e7eb, #94a3b8)',
    2: 'linear-gradient(135deg, #fdba74, #c2701c)',
};
function RankBadge({ idx }) {
    const grad = MEDALHA[idx];
    if (grad) {
        return (
            <span className="grid h-8 w-8 place-items-center rounded-full font-display text-[13px] font-extrabold text-black shadow-md"
                  style={{ background: grad }}>
                {idx + 1}
            </span>
        );
    }
    return (
        <span className="grid h-8 w-8 place-items-center rounded-full bg-white/[0.04] text-[13px] font-semibold tabular-nums text-white/40">
            {idx + 1}
        </span>
    );
}

// Barra de score + número (cor por faixa). Cresce 0 → valor na montagem, com
// glow por faixa e trilho embutido — o score é indicador-chave, então ganha peso.
function ScoreBarra({ value, mounted, delay = 0 }) {
    const b = bandaScore(value);
    const w = mounted ? Math.min(value ?? 0, 100) : 0;
    return (
        <div className="flex items-center justify-end gap-2.5">
            <div className="relative h-2 w-20 overflow-hidden rounded-full bg-white/[0.08]">
                <div className="h-full rounded-full transition-[width] duration-[900ms] ease-out"
                     style={{
                         width: `${w}%`,
                         background: `linear-gradient(90deg, ${b.cor}aa, ${b.cor})`,
                         boxShadow: `0 0 10px ${b.cor}66`,
                         transitionDelay: `${delay}ms`,
                     }} />
            </div>
            <span className="w-11 text-right font-display text-[17px] font-extrabold leading-none tabular-nums" style={{ color: b.cor }}>
                {fmtScore(value)}
            </span>
        </div>
    );
}

// Mini barra de % da meta (substitui número solto no ranking — ponto "mais visual").
// Cor por atingimento; trilho fino; cresce na montagem.
function MetaMiniBar({ value, mounted, delay = 0 }) {
    if (value === null || value === undefined) return <span className="font-bold text-white/20">—</span>;
    const cor = value >= 100 ? ACCENTS.green : value >= 70 ? ACCENTS.yellow : ACCENTS.red;
    const w = mounted ? Math.min(value, 100) : 0;
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-12 overflow-hidden rounded-full bg-white/[0.08]">
                <div className="h-full rounded-full transition-[width] duration-[900ms] ease-out"
                     style={{ width: `${w}%`, background: cor, transitionDelay: `${delay}ms` }} />
            </div>
            <span className="w-11 text-right text-[12px] font-semibold tabular-nums" style={{ color: cor }}>{fmtPct1(value)}</span>
        </div>
    );
}

function StatusPill({ status }) {
    const cfg = STATUS_PILL[status] ?? { cor: '#9ca3af', label: status ?? '—' };
    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold"
              style={{ background: `${cfg.cor}1f`, color: cfg.cor }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: cfg.cor }} />
            {cfg.label}
        </span>
    );
}

// Indicador de evolução no ranking (mês vs mês anterior). '—' quando sem base.
function Evolucao({ delta }) {
    if (delta === null || delta === undefined) return <span className="font-bold text-white/20">—</span>;
    if (delta > 0) return <span className="inline-flex items-center gap-0.5 text-[13px] font-semibold text-emerald-400"><ArrowUp size={13} />{delta}</span>;
    if (delta < 0) return <span className="inline-flex items-center gap-0.5 text-[13px] font-semibold text-red-400"><ArrowDown size={13} />{Math.abs(delta)}</span>;
    return <span className="inline-flex items-center text-white/35"><Minus size={13} /></span>;
}

// Relógio ao vivo — re-renderiza só a si mesmo (isolado), a cada 30s.
function LiveClock() {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 30_000);
        return () => clearInterval(id);
    }, []);
    return <span className="tabular-nums">{now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span>;
}

// Score/nota circular grande (reaproveita RadialGauge da Torre de Comando).
// O arco usa score100 (0-100); o centro mostra a nota do plano (0-5) quando dada.
function ScoreGauge({ value, nota = null, size = 116 }) {
    const pct = Math.min(Math.max(value ?? 0, 0), 100);
    const mostraNota = nota !== null && nota !== undefined;
    return (
        <div className="relative grid shrink-0 place-items-center" style={{ width: size, height: size }}>
            <RadialGauge pct={pct} size={size} />
            <div className="absolute inset-0 flex flex-col items-center justify-center leading-none">
                <span className="font-display font-extrabold leading-none tabular-nums text-white text-xl">
                    {mostraNota ? fmtNota(nota) : fmtScore(value)}
                </span>
                <span className="mt-0.5 text-[10px] uppercase leading-none tracking-wider text-white/35">{mostraNota ? '/ 5' : '/ 100'}</span>
            </div>
        </div>
    );
}

function MiniStat({ label, value, sub, accent, icon: Icon }) {
    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5">
            <p className="flex items-center gap-1 text-[10px] uppercase tracking-wider text-white/40">
                {Icon && <Icon size={11} className="shrink-0" style={accent ? { color: accent } : undefined} />}
                {label}
            </p>
            <p className="mt-1 font-display text-xl font-extrabold leading-none tabular-nums text-white" style={accent ? { color: accent } : undefined}>{value}</p>
            {sub && <p className="mt-1 text-[10px] text-white/35">{sub}</p>}
        </div>
    );
}

// Selo de evolução no ranking em relação ao mês anterior (usado no destaque).
function EvolucaoTag({ delta }) {
    if (delta === null || delta === undefined) {
        return <span className="inline-flex items-center gap-1 rounded-full bg-white/[0.04] px-2 py-0.5 text-[11px] font-semibold text-white/35">novo</span>;
    }
    if (delta > 0) return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-300"><ArrowUp size={12} />subiu {delta}</span>;
    if (delta < 0) return <span className="inline-flex items-center gap-1 rounded-full bg-red-500/15 px-2 py-0.5 text-[11px] font-semibold text-red-300"><ArrowDown size={12} />caiu {Math.abs(delta)}</span>;
    return <span className="inline-flex items-center gap-1 rounded-full bg-white/[0.04] px-2 py-0.5 text-[11px] font-semibold text-white/45"><Minus size={12} />manteve</span>;
}

// Bloco 2a — destaque do melhor colaborador (sem foto obrigatória).
// Aproveita todo o espaço: gauge de score + 4 ministats (inclui Projeção, antes
// só calculada no backend) + barra de meta com "faltam X" (ou meta batida).
function LeaderHero({ lider, mounted, animar }) {
    const banda   = bandaScore(lider.score_final);
    const w       = mounted ? Math.min(lider.percentual ?? 0, 100) : 0;
    const faltam  = Math.max((lider.meta ?? 0) - (lider.feito ?? 0), 0);
    const bateu   = faltam === 0 && (lider.meta ?? 0) > 0;

    return (
        <SectionCard accent={ACCENTS.yellow} glow className="flex h-full flex-col gap-5 p-6">
            <div className="flex items-center justify-between">
                <span className="inline-flex items-center gap-2 text-ecf-yellow">
                    <Trophy size={18} />
                    <span className="text-[13px] font-semibold uppercase tracking-wider">Melhor do mês</span>
                </span>
                <EvolucaoTag delta={lider.evolucao_delta} />
            </div>

            <div className="flex items-center gap-4">
                <div className="relative shrink-0">
                    <Avatar nome={lider.name} src={lider.foto} size={76} destaque />
                    <span className="absolute -right-1 -top-1 grid h-6 w-6 place-items-center rounded-full bg-ecf-bg text-ecf-yellow ring-1 ring-ecf-yellow/40">
                        <Crown size={13} />
                    </span>
                </div>
                <div className="min-w-0">
                    <h2 className="truncate font-display text-2xl font-extrabold leading-tight text-white">{lider.name}</h2>
                    <p className="text-[13px] text-white/45">{pubRoleLabel[lider.pub_role] ?? 'Publicador'}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2"><StatusPill status={lider.status} /><FaixaBadge faixa={lider.faixa} size="sm" /></div>
                </div>
            </div>

            <div className="flex items-center gap-5">
                <ScoreGauge value={lider.score_final} nota={lider.nota} size={116} />
                <div className="grid flex-1 grid-cols-2 gap-2.5">
                    <MiniStat label="Produção" icon={CheckCircle2} value={<AnimatedNumber value={lider.feito} animar={animar} />} sub={`meta ${fmtInt(lider.meta)}`} />
                    <MiniStat label="Vendas" icon={ShoppingCart} accent={ACCENTS.blue} value={<AnimatedNumber value={lider.vendas} animar={animar} />} />
                    <MiniStat label="Conversão" icon={Percent} value={lider.conversao > 0 ? fmtPct1(lider.conversao) : '—'} />
                    <MiniStat label="Projeção" icon={Rocket} accent={ACCENTS.violet} value={<AnimatedNumber value={lider.projecao} animar={animar} />} sub="fim do mês" />
                </div>
            </div>

            <div className="mt-auto">
                <div className="mb-1.5 flex items-center justify-between text-[12px]">
                    <span className="text-white/50">Atingimento da meta</span>
                    <span className="font-semibold tabular-nums" style={{ color: banda.cor }}>{fmtPct1(lider.percentual)}</span>
                </div>
                <div className="h-2.5 overflow-hidden rounded-full bg-white/[0.06]">
                    <div className="h-full rounded-full transition-[width] duration-[900ms] ease-out"
                         style={{ width: `${w}%`, background: `linear-gradient(90deg, ${banda.cor}aa, ${banda.cor})`, boxShadow: `0 0 12px ${banda.cor}55` }} />
                </div>
                <p className="mt-1.5 text-[11px] text-white/40">
                    {bateu
                        ? <span className="inline-flex items-center gap-1 text-emerald-300"><CheckCircle2 size={12} /> Meta batida no mês</span>
                        : <>Faltam <span className="font-semibold text-white/70 tabular-nums">{fmtInt(faltam)}</span> publicações pra meta</>}
                </p>
            </div>
        </SectionCard>
    );
}

// Cor da barra de produção pelo status (comunica "no trilho?" além do volume).
const statusCor = (status) => STATUS_PILL[status]?.cor ?? '#9ca3af';

// Bloco 2b — produção por publicador (barras horizontais CSS, leves para TV).
// Enriquecido: gradiente por status, marcador da meta na barra, % da meta,
// glow+sheen no líder de produção e crescimento escalonado por índice.
function ProducaoBars({ ranking, mounted }) {
    const ord = useMemo(() => [...ranking].sort((a, b) => (b.feito ?? 0) - (a.feito ?? 0)), [ranking]);
    const max = Math.max(...ord.map((r) => r.feito ?? 0), 1);
    return (
        <SectionCard accent={ACCENTS.blue} glow className="flex h-full flex-col p-6">
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-white/[0.04] text-blue-300"><BarChart3 size={16} /></span>
                    <h3 className="font-display text-[15px] font-bold text-white">Produção por publicador</h3>
                </div>
                <span className="hidden items-center gap-1.5 text-[10px] text-white/35 sm:inline-flex">
                    <span className="h-2.5 w-px bg-white/40" /> marca da meta
                </span>
            </div>
            <div className="flex flex-1 flex-col justify-center gap-2.5">
                {ord.map((r, i) => {
                    const cor       = statusCor(r.status);
                    const w         = mounted ? ((r.feito ?? 0) / max) * 100 : 0;
                    const metaX     = Math.min(((r.meta ?? 0) / max) * 100, 100);
                    const isLider   = i === 0;
                    const delay     = i * 70; // escalonamento suave do crescimento
                    return (
                        <div key={r.id} className="group flex items-center gap-3 rounded-lg px-1.5 py-1 transition-colors hover:bg-white/[0.03]">
                            <span className={cn('w-28 shrink-0 truncate text-[13px] sm:w-32', isLider ? 'font-semibold text-white' : 'text-white/75')}>
                                {isLider && <Flame size={12} className="mr-1 inline text-ecf-yellow" />}{r.name}
                            </span>
                            <div className="relative h-3 flex-1 overflow-hidden rounded-full bg-white/[0.05]">
                                {/* preenchimento por status, com glow no líder */}
                                <div className="relative h-full rounded-full transition-[width] duration-[900ms] ease-out"
                                     style={{
                                         width: `${w}%`,
                                         background: `linear-gradient(90deg, ${cor}99, ${cor})`,
                                         boxShadow: isLider ? `0 0 12px ${cor}88` : 'none',
                                         transitionDelay: `${delay}ms`,
                                     }}>
                                    {/* sheen só no líder — brilho discreto varrendo a barra */}
                                    {isLider && (
                                        <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1/3 animate-sheen rounded-full motion-reduce:hidden"
                                              style={{ background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.45), transparent)' }} />
                                    )}
                                </div>
                                {/* marcador da meta — linha vertical sutil */}
                                {metaX > 0 && metaX < 100 && (
                                    <span aria-hidden="true" className="absolute inset-y-0 w-px bg-white/45"
                                          style={{ left: `${metaX}%` }} title={`meta ${fmtInt(r.meta)}`} />
                                )}
                            </div>
                            <span className="w-10 shrink-0 text-right font-display text-[15px] font-extrabold tabular-nums text-white">{fmtInt(r.feito)}</span>
                            <span className="hidden w-11 shrink-0 text-right text-[11px] font-semibold tabular-nums sm:inline"
                                  style={{ color: cor }}>{fmtPct1(r.percentual)}</span>
                        </div>
                    );
                })}
            </div>
        </SectionCard>
    );
}

// Bloco 3 — ranking completo, em cartões (sem cara de DataTable).
// Cores sólidas das medalhas p/ a faixa lateral de destaque do Top 3.
const MEDAL_SOLID = { 0: '#eab308', 1: '#94a3b8', 2: '#c2701c' };
const RANK_GRID = 'grid grid-cols-[3rem_minmax(190px,1fr)_5.5rem_8rem_5rem_5rem_9.5rem_8rem_3.5rem] items-center gap-3';

// Célula de conversão com ponto colorido (mais visual que número solto).
function ConversaoCell({ value }) {
    if (!(value > 0)) return <span className="font-bold text-white/20">—</span>;
    const cor = value >= 8 ? ACCENTS.green : value >= 4 ? ACCENTS.yellow : ACCENTS.blue;
    return (
        <span className="inline-flex items-center gap-1.5 text-[13px] font-semibold tabular-nums text-white/80">
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: cor }} />{fmtPct1(value)}
        </span>
    );
}

function RankingModerno({ ranking, mounted, metaUniforme }) {
    return (
        <SectionCard accent={ACCENTS.yellow}>
            <div className="flex flex-wrap items-center gap-2 px-5 py-4">
                <Trophy size={16} className="text-ecf-yellow" />
                <h3 className="font-display text-[15px] font-bold text-white">Ranking completo</h3>
                <span className="text-[12px] text-white/30">· {ranking.length} publicadores</span>
                {metaUniforme !== null && (
                    <span className="ml-auto inline-flex items-center gap-1.5 rounded-full border border-white/[0.08] bg-white/[0.03] px-3 py-1 text-[11px] font-medium text-white/55">
                        <Target size={12} className="text-ecf-yellow/70" />
                        Meta: <span className="font-semibold tabular-nums text-white/80">{fmtInt(metaUniforme)}</span> / publicador
                    </span>
                )}
            </div>
            <div className="overflow-x-auto px-3 pb-3">
                <div className="min-w-[860px]">
                    {/* legenda enxuta (não-uppercase pesado) só p/ orientar a leitura */}
                    <div className={cn(RANK_GRID, 'px-4 pb-2 text-[10px] font-medium tracking-wide text-white/25')}>
                        <span className="text-center">#</span>
                        <span>Publicador</span>
                        <span className="text-right">Feito</span>
                        <span className="text-right">% da meta</span>
                        <span className="text-right">Vendas</span>
                        <span className="text-right">Conv.</span>
                        <span className="text-right">Nota</span>
                        <span className="text-center">Status</span>
                        <span className="text-center">Evol.</span>
                    </div>
                    <div className="space-y-1.5">
                        {ranking.map((u, idx) => {
                            const top3     = idx < 3;
                            const medalCor = MEDAL_SOLID[idx];
                            return (
                                <div key={u.id}
                                     className={cn(
                                         RANK_GRID,
                                         'relative rounded-xl border px-4 transition-colors',
                                         top3 ? 'border-white/[0.08] bg-white/[0.04] py-4' : 'border-white/[0.05] bg-white/[0.02] py-3 hover:bg-white/[0.04]',
                                     )}
                                     style={top3 ? { boxShadow: `inset 3px 0 0 0 ${medalCor}, 0 0 26px -14px ${medalCor}` } : undefined}>
                                    <div className="flex justify-center"><RankBadge idx={idx} /></div>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <Avatar nome={u.name} src={u.foto} size={top3 ? 38 : 32} destaque={idx === 0} />
                                        <div className="min-w-0">
                                            <p className={cn('truncate font-semibold text-white', top3 ? 'text-[14px]' : 'text-[13px]')}>{u.name}</p>
                                            <p className="text-[11px] text-white/35">{pubRoleLabel[u.pub_role] ?? 'Publicador'}</p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <span className="font-display text-[17px] font-extrabold tabular-nums text-white">{fmtInt(u.feito)}</span>
                                        {metaUniforme === null && <span className="ml-1 text-[11px] tabular-nums text-white/30">/{fmtInt(u.meta)}</span>}
                                    </div>
                                    <div className="flex justify-end"><MetaMiniBar value={u.percentual} mounted={mounted} delay={idx * 60} /></div>
                                    <div className="text-right text-[13px] font-semibold tabular-nums text-blue-400">{fmtInt(u.vendas)}</div>
                                    <div className="flex justify-end"><ConversaoCell value={u.conversao} /></div>
                                    <div className="flex justify-end"><NotaCell nota={u.nota} faixa={u.faixa} score100={u.score_final} mounted={mounted} delay={idx * 60} /></div>
                                    <div className="flex justify-center"><StatusPill status={u.status} /></div>
                                    <div className="flex justify-center"><Evolucao delta={u.evolucao_delta} /></div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </SectionCard>
    );
}

// Bloco 4 — ticker de destaques (marquee CSS; pausa no hover).
function construirDestaques(ranking, resumo) {
    const arr = [];
    const { lider, totalVendas, pctMeta } = resumo;
    if (lider) arr.push({ icon: Crown, text: `${lider.name} lidera com nota ${fmtNota(lider.nota)} (${(FAIXA[lider.faixa] ?? FAIXA.sem_bonus).label})` });

    const maisProd = [...ranking].sort((a, b) => (b.feito ?? 0) - (a.feito ?? 0))[0];
    if (maisProd) arr.push({ icon: Flame, text: `${maisProd.name} é quem mais produz: ${fmtInt(maisProd.feito)} publicações` });

    const comConv = ranking.filter((r) => (r.conversao ?? 0) > 0).sort((a, b) => b.conversao - a.conversao)[0];
    if (comConv) arr.push({ icon: Percent, text: `Melhor conversão: ${comConv.name} (${fmtPct1(comConv.conversao)})` });

    arr.push({ icon: ShoppingCart, text: `${fmtInt(totalVendas)} vendas no mês pela equipe` });
    arr.push({ icon: Target, text: `Meta geral em ${fmtPct1(pctMeta)}` });

    const subiu = ranking.filter((r) => (r.evolucao_delta ?? 0) > 0).sort((a, b) => b.evolucao_delta - a.evolucao_delta)[0];
    if (subiu) arr.push({ icon: ArrowUp, text: `${subiu.name} subiu ${subiu.evolucao_delta} posição${subiu.evolucao_delta > 1 ? 'ões' : ''} no ranking` });

    const noAlvo = ranking.filter((r) => r.status === 'Acima da meta' || r.status === 'No alvo').length;
    arr.push({ icon: CheckCircle2, text: `${fmtInt(noAlvo)} de ${fmtInt(ranking.length)} publicadores no alvo` });

    return arr;
}

// Um "grupo" = a lista completa de destaques. Renderizamos DOIS grupos idênticos
// e animamos a trilha em -50%: cada grupo tem a mesma largura, então o ciclo fecha
// sem emenda. O pl-8 é uniforme nos dois grupos (não quebra a simetria).
function TickerGroup({ itens, ariaHidden }) {
    return (
        <div className="flex shrink-0 items-center gap-10 pl-10" aria-hidden={ariaHidden || undefined}>
            {itens.map((d, i) => {
                const Ico = d.icon;
                return (
                    <span key={i} className="inline-flex items-center gap-2.5 whitespace-nowrap text-[13px] text-white/70">
                        <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ecf-yellow/10 text-ecf-yellow">
                            <Ico size={13} />
                        </span>
                        {d.text}
                        <span className="ml-1 h-1 w-1 rounded-full bg-white/20" />
                    </span>
                );
            })}
        </div>
    );
}

// Máscara de fade nas duas pontas — a rolagem "nasce" e "some" suavemente.
const TICKER_FADE = {
    maskImage: 'linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent)',
    WebkitMaskImage: 'linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent)',
};

function Ticker({ itens }) {
    if (!itens.length) return null;
    return (
        <SectionCard accent={ACCENTS.yellow}>
            <div className="flex items-stretch">
                <div className="flex shrink-0 items-center gap-2 border-r border-white/[0.08] bg-ecf-yellow/[0.06] px-4 py-3.5 text-ecf-yellow">
                    <Megaphone size={15} />
                    <span className="text-[12px] font-bold uppercase tracking-wider">Destaques</span>
                </div>
                <div className="group relative flex-1 overflow-hidden py-3.5" style={TICKER_FADE}>
                    <div className="flex w-max animate-marquee group-hover:[animation-play-state:paused]">
                        <TickerGroup itens={itens} />
                        <TickerGroup itens={itens} ariaHidden />
                    </div>
                </div>
            </div>
        </SectionCard>
    );
}

function DashboardHeader({ mes, meses, onMes, tvMode = false, onToggleTv }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
                <span className="grid h-11 w-11 place-items-center rounded-xl bg-ecf-yellow/10 text-ecf-yellow"><Trophy size={22} /></span>
                <div>
                    <h1 className="font-display text-xl font-extrabold leading-none text-white">Desempenho da Equipe</h1>
                    <p className="mt-1 text-[13px] text-white/40">Publicações · {mes ? formatMesLabel(mes) : '—'}</p>
                </div>
            </div>
            <div className="flex items-center gap-3">
                <div className="hidden items-center gap-1.5 text-[12px] text-white/40 sm:flex">
                    <Clock size={13} /><LiveClock />
                    <span className="text-white/20">·</span>
                    <span className="inline-flex items-center gap-1"><span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" /> ao vivo</span>
                </div>
                <SelectBox value={mes} onChange={onMes}>
                    {meses.map((m) => <option key={m} value={m}>{formatMesLabel(m)}</option>)}
                </SelectBox>
                {onToggleTv && (
                    tvMode ? (
                        <button
                            onClick={onToggleTv}
                            className="flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm text-white/40 transition-all hover:border-white/20 hover:text-white"
                        >
                            <X size={14} /> Sair do modo TV
                        </button>
                    ) : (
                        <button
                            onClick={onToggleTv}
                            className="flex items-center gap-1.5 h-9 rounded-xl bg-ecf-yellow px-3 text-[13px] font-bold text-[#252525] transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20"
                        >
                            <Tv size={13} /> Modo TV
                        </button>
                    )
                )}
            </div>
        </div>
    );
}

// Overlay fullscreen do Modo TV — espelha o padrão do Dashboard principal
// (fixed inset-0 z-50), trava o scroll do body e mantém o fundo da página.
function TvShell({ children }) {
    useEffect(() => {
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = prev; };
    }, []);
    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-ecf-bg p-5 2xl:p-6">
            {children}
        </div>
    );
}

// Mini "termômetro" de score: faixa colorida 0–100 com marcador na posição.
function ScoreBandMini({ value }) {
    const pos = Math.min(Math.max(value ?? 0, 0), 100);
    return (
        <div className="relative h-1.5 w-full overflow-hidden rounded-full"
             style={{ background: 'linear-gradient(90deg, #ef4444, #f97316 35%, #ffe600 62%, #22c55e)' }}>
            <span className="absolute top-1/2 h-3 w-1 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow"
                  style={{ left: `${pos}%` }} />
        </div>
    );
}

// Bloco 1b — situação da equipe em relance (KPIs rápidos p/ exibição contínua).
// Todos derivados do ranking atual; "subiram" usa evolucao_delta vs mês anterior.
function TeamStatusStrip({ stats }) {
    const itens = [
        { label: 'No alvo',            value: fmtInt(stats.noAlvo),  sub: `de ${fmtInt(stats.total)}`, cor: ACCENTS.green,  icon: CheckCircle2 },
        { label: 'Abaixo da meta',     value: fmtInt(stats.abaixo),  sub: 'requer foco',               cor: ACCENTS.red,    icon: TrendingDown },
        { label: 'Média / publicador', value: fmtInt(stats.mediaProducao), sub: 'publicações',         cor: ACCENTS.blue,   icon: Activity },
        { label: 'Conversão média',    value: stats.conversaoMedia > 0 ? fmtPct1(stats.conversaoMedia) : '—', sub: 'da equipe', cor: ACCENTS.yellow, icon: Gauge },
        { label: 'Subiram no ranking', value: fmtInt(stats.subiram), sub: 'vs mês anterior',           cor: ACCENTS.violet, icon: ArrowUp },
    ];
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            {itens.map((it) => {
                const Ico = it.icon;
                return (
                    <div key={it.label} className={cn(CARD, 'flex items-center gap-3 p-3.5')}>
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg"
                              style={{ background: `${it.cor}1f`, color: it.cor }}>
                            <Ico size={16} />
                        </span>
                        <div className="min-w-0">
                            <p className="font-display text-lg font-extrabold leading-none tabular-nums text-white">{it.value}</p>
                            <p className="mt-1 truncate text-[11px] text-white/45">{it.label} · {it.sub}</p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function PolosDashboard({ ranking, mes, meses = [] }) {
    const mounted = useMounted();
    const reduz   = usePrefersReducedMotion();
    const animar  = mounted && !reduz;

    // ── Modo TV ──────────────────────────────────────────────────────────
    // Igual ao Dashboard principal: um overlay fixed inset-0 z-50 que ocupa a
    // tela toda (some a sidebar/header do app). Além do overlay CSS, dispara o
    // Fullscreen real do navegador — aí some também a barra do browser (TV/kiosk).
    const [tvMode, setTvMode] = useState(false);

    const entrarTv = () => {
        setTvMode(true);
        document.documentElement.requestFullscreen?.().catch(() => {}); // ignora se o browser bloquear
    };
    const sairTv = () => {
        setTvMode(false);
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
    };
    const toggleTv = () => (tvMode ? sairTv() : entrarTv());

    // Sair do fullscreen do browser (ESC/F11) também sai do Modo TV — mantém sync.
    useEffect(() => {
        const onFs = () => { if (!document.fullscreenElement) setTvMode(false); };
        document.addEventListener('fullscreenchange', onFs);
        return () => document.removeEventListener('fullscreenchange', onFs);
    }, []);

    // ESC encerra o Modo TV mesmo quando o fullscreen do browser não engatou.
    useEffect(() => {
        if (!tvMode) return;
        const onKey = (e) => { if (e.key === 'Escape') sairTv(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [tvMode]);

    // Auto-refresh do ranking a cada 5 min (fica exposto numa TV o dia todo).
    // Recarrega só a prop 'ranking' (partial reload), e apenas com a aba visível.
    useEffect(() => {
        const id = setInterval(() => {
            if (!document.hidden) {
                router.reload({ only: ['ranking'], preserveScroll: true, preserveState: true });
            }
        }, 5 * 60 * 1000);
        return () => clearInterval(id);
    }, []);

    // Troca de mês usa a rota própria de Publicações (evita cair no ranking de consultoria).
    const trocarMes = (m) => router.get(route('publicacao.desempenho.index'), { mes: m }, { preserveState: true, preserveScroll: true });

    const resumo = useMemo(() => {
        const publicadores = ranking.length;
        const metaGeral    = ranking.reduce((s, r) => s + (r.meta ?? 0), 0);
        const produzido    = ranking.reduce((s, r) => s + (r.feito ?? 0), 0);
        const totalVendas  = ranking.reduce((s, r) => s + (r.vendas ?? 0), 0);
        const pctMeta      = metaGeral > 0 ? (produzido / metaGeral) * 100 : 0;
        const lider        = ranking[0] ?? null;

        // Situação da equipe (strip) + detecção de meta uniforme (ponto 6).
        const noAlvo       = ranking.filter((r) => r.status === 'Acima da meta' || r.status === 'No alvo').length;
        const abaixo       = ranking.filter((r) => r.status === 'Abaixo da meta').length;
        const mediaProducao= publicadores > 0 ? Math.round(produzido / publicadores) : 0;
        const comConv      = ranking.filter((r) => (r.conversao ?? 0) > 0);
        const conversaoMedia = comConv.length ? comConv.reduce((s, r) => s + r.conversao, 0) / comConv.length : 0;
        const subiram      = ranking.filter((r) => (r.evolucao_delta ?? 0) > 0).length;
        const metasDistintas = [...new Set(ranking.map((r) => r.meta ?? 0))];
        const metaUniforme = metasDistintas.length === 1 ? metasDistintas[0] : null;

        return {
            publicadores, metaGeral, produzido, totalVendas, pctMeta, lider,
            total: publicadores, noAlvo, abaixo, mediaProducao, conversaoMedia, subiram, metaUniforme,
        };
    }, [ranking]);

    // Perfil de produção da equipe (ordem decrescente) — sparkline real do KPI "Produzido".
    const sparkProducao = useMemo(
        () => [...ranking].map((r) => r.feito ?? 0).sort((a, b) => b - a),
        [ranking],
    );

    const destaques = useMemo(() => (resumo.lider ? construirDestaques(ranking, resumo) : []), [ranking, resumo]);

    if (!ranking.length) {
        return (
            <AppLayout title="Desempenho">
                <div className="w-full">
                    <DashboardHeader mes={mes} meses={meses} onMes={trocarMes} />
                    <div className="card-ecf mt-5 rounded-2xl p-16 text-center">
                        <Trophy size={34} className="mx-auto mb-3 text-white/20" />
                        <p className="text-sm text-white/40">Nenhum publicador com dados neste mês</p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const { lider } = resumo;

    const conteudo = (
        <div className="relative w-full">
            <DashboardBackdrop />

            <div className="relative space-y-4 2xl:space-y-5">
                <DashboardHeader mes={mes} meses={meses} onMes={trocarMes} tvMode={tvMode} onToggleTv={toggleTv} />

                {/* 1 · KPIs de resumo — cada card com identidade cromática + detalhe gráfico */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
                    <HeroKpi
                        titulo="Publicadores" icone={Users} accentColor={ACCENTS.blue}
                        valor={<AnimatedNumber value={resumo.publicadores} animar={animar} />} sublabel="ativos no mês"
                        extra={
                            <div className="flex items-center gap-3 text-[11px]">
                                <span className="inline-flex items-center gap-1 text-emerald-300"><span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />{fmtInt(resumo.noAlvo)} no alvo</span>
                                <span className="inline-flex items-center gap-1 text-red-300"><span className="h-1.5 w-1.5 rounded-full bg-red-400" />{fmtInt(resumo.abaixo)} abaixo</span>
                            </div>
                        }
                    />
                    <HeroKpi
                        titulo="Meta Geral" icone={Target} accentColor={ACCENTS.violet}
                        valor={<AnimatedNumber value={resumo.metaGeral} animar={animar} />} sublabel="publicações no mês"
                    />
                    <HeroKpi
                        titulo="Produzido" icone={CheckCircle2} glow="green" accentColor={ACCENTS.green}
                        valor={<AnimatedNumber value={resumo.produzido} animar={animar} />} sublabel={`${fmtInt(resumo.totalVendas)} vendas`}
                        extra={<Sparkline data={sparkProducao} cor={ACCENTS.green} width={132} height={22} />}
                    />
                    <HeroKpi
                        titulo="% da Meta" icone={TrendingUp} valor={fmtPct1(resumo.pctMeta)}
                        gauge={Math.min(resumo.pctMeta, 100)} sublabel="atingimento geral da equipe"
                    />
                    <HeroKpi
                        titulo="Melhor Nota" icone={Award} glow="yellow" accentColor={faixaCor(lider.faixa)}
                        valor={fmtNota(lider.nota)} sublabel={`${primeiroNome(lider.name)} · nota de 0 a 5`}
                        extra={<FaixaBadge faixa={lider.faixa} size="sm" />}
                    />
                    <HeroKpi
                        titulo="Líder" icone={Crown} glow="yellow" accentColor={ACCENTS.yellow}
                        valor={primeiroNome(lider.name)} sublabel={pubRoleLabel[lider.pub_role] ?? 'Publicador'}
                        extra={<StatusPill status={lider.status} />}
                    />
                </div>

                {/* 1b · Situação da equipe */}
                <TeamStatusStrip stats={resumo} />

                {/* 2 · Destaque do líder + produção por publicador */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div className="lg:col-span-5"><LeaderHero lider={lider} mounted={mounted} animar={animar} /></div>
                    <div className="lg:col-span-7"><ProducaoBars ranking={ranking} mounted={mounted} /></div>
                </div>

                {/* 3 · Ranking completo */}
                <RankingModerno ranking={ranking} mounted={mounted} metaUniforme={resumo.metaUniforme} />

                {/* 4 · Ticker de destaques */}
                <Ticker itens={destaques} />
            </div>
        </div>
    );

    // Modo TV = overlay fullscreen (sem sidebar/header do app). Normal = AppLayout.
    return tvMode
        ? <TvShell>{conteudo}</TvShell>
        : <AppLayout title="Desempenho">{conteudo}</AppLayout>;
}
