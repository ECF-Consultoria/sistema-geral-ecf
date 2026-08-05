import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import {
    ArrowLeft, Star, TrendingUp, TrendingDown, Coins, Calendar,
    Trophy, Sparkles, UserX, BookOpen, Info, Briefcase, ChevronRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import EmpresasScoreTabela from '@/Components/Desempenho/EmpresasScoreTabela';
import {
    MARGEM_CARD_TITULO, MARGEM_CARD_SUBLABEL, fraseVarMargemPp,
    AVISO_SEM_DETALHE_TITULO, AVISO_SEM_DETALHE_EM_CURSO, avisoSemDetalheFechado,
} from '@/lib/desempenhoLabels';

/**
 * Nota em escala 0-5 com 2 casas SEMPRE — "4,03", nunca "4" nem "4,1".
 * Desde 2026-08-05 os componentes são médias de N lojas, então quase nunca
 * caem em valor redondo; casas variáveis faziam a mesma tela mostrar "4",
 * "4,1" e "4,03" lado a lado, e a conta parecia não fechar.
 */
function formatNota(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(2).replace('.', ',');
}

/**
 * Formata a conta que produziu a nota (ex: "(4,65+3,83+3,61)/3 = 4,03").
 * Consumido em FaixaBonusCard abaixo da nota final — mesmo formato usado no
 * Ranking (Performance/Index.jsx). Nulls (componentes indisponíveis) ficam
 * de fora, e o denominador acompanha quantos componentes sobraram.
 */
function formatContaNota(pontos, notaFinal) {
    if (!pontos) return null;
    const pts = [pontos.nps, pontos.faturamento, pontos.margem].filter((v) => v != null);
    if (pts.length === 0) return null;
    const notaFmt = notaFinal != null ? formatNota(notaFinal) : '?';
    return `(${pts.map(formatNota).join('+')})/${pts.length} = ${notaFmt}`;
}

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

// ─── Status de elegibilidade da nota (Fase 92 · DESEMP-08) ────────────────
// Mesmos labels TRAVADOS de Performance/Index.jsx — nunca renderizar o slug
// cru. 'official' não ganha badge (nota já é a oficial, sem ressalva).
const SCORE_STATUS_LABEL = {
    blocked: 'Aguarda régua Shopee',
    partial: 'Parcial',
    official: 'Oficial',
};
const SCORE_STATUS_BADGE_CLS = {
    blocked: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    partial: 'bg-amber-500/10 text-amber-200/70 border-amber-500/20',
};
// Explicação pt-BR, sem jargão técnico, complementando o "Sem dados
// suficientes..." já existente quando a nota não é oficial.
const SCORE_STATUS_EXPLICACAO = {
    blocked: 'Carteira sem vínculo com fonte financeira ainda — aguardando a régua de bônus da diretoria para a Shopee.',
    partial: 'Parte dos componentes do cálculo ainda não está disponível para esta carteira — a nota pode mudar quando os dados chegarem.',
};

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

// 'YYYY-MM-DD' → 'DD/MM' (para o intervalo de baseline no banner de mês fechado).
function fmtDia(iso) {
    if (!iso) return '';
    const s = String(iso);
    return `${s.slice(8, 10)}/${s.slice(5, 7)}`;
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
function FaixaBonusCard({ resultado, user }) {
    const slug = resultado?.faixa_bonus;
    const nota = resultado?.nota_final;
    const promovida = resultado?.faixa_promovida === true;
    const cor = corFaixa(slug);
    // Ajuste 2026-07-13 · conta que gerou a nota (mesmo padrão do ranking).
    const contaNota = formatContaNota(resultado?.pontos_componentes, nota);
    // Fase 92 (DESEMP-08) · status de elegibilidade + os 4 metadados que o
    // justificam — já vêm prontos em `resultado` (passthrough do backend 92-01).
    const scoreStatus = resultado?.score_status;
    const temMetadados = resultado?.empresas_unicas != null
        || resultado?.vinculos_servico != null
        || resultado?.vinculos_financeiros != null
        || resultado?.vinculos_sem_fonte_financeira != null;

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

                <div className="md:ml-auto flex flex-col md:items-end gap-1">
                    <div className="flex items-baseline gap-2">
                        <span className="text-[10px] uppercase tracking-wider font-bold text-white/50">
                            Nota final
                        </span>
                        <span className="text-white text-4xl font-display font-black tabular-nums leading-none">
                            {formatNota(nota)}
                        </span>
                        <span className="text-white/40 text-sm">/ 5,00</span>
                        {/* Fase 92 (DESEMP-08) · badge de status da nota. 'official' não
                            ganha badge (nota já é a oficial, sem ressalva). */}
                        {scoreStatus && scoreStatus !== 'official' && (
                            <span
                                className={cn(
                                    'inline-flex items-center text-[10px] font-semibold px-2 py-0.5 rounded-full border',
                                    SCORE_STATUS_BADGE_CLS[scoreStatus] ?? SCORE_STATUS_BADGE_CLS.partial,
                                )}
                            >
                                {SCORE_STATUS_LABEL[scoreStatus] ?? scoreStatus}
                            </span>
                        )}
                    </div>
                    {/* Ajuste 2026-07-13 · conta que gerou a nota. Mesma
                        semântica do "( x+y+z )/n" mostrado abaixo do nome no
                        ranking, agora com "= nota" pra fechar o cálculo. */}
                    {contaNota && (
                        <span
                            className="text-white/40 text-xs tabular-nums"
                            title="Média dos pontos NPS, faturamento e margem (régua 1-5)"
                        >
                            {contaNota}
                        </span>
                    )}
                </div>
            </div>

            {nota == null && (
                <p className="relative mt-4 text-white/60 text-sm">
                    Sem dados suficientes para classificação no mês selecionado.
                    {scoreStatus && SCORE_STATUS_EXPLICACAO[scoreStatus] && (
                        <> {SCORE_STATUS_EXPLICACAO[scoreStatus]}</>
                    )}
                </p>
            )}

            {/* Fase 92 (SC2) · metadados de elegibilidade — bloco discreto,
                pt-BR sem jargão técnico. */}
            {temMetadados && (
                <div className="relative mt-4 pt-4 border-t border-white/[0.06] grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Empresas únicas</p>
                        <p className="text-white/80 text-sm font-semibold tabular-nums mt-0.5">{resultado?.empresas_unicas ?? 0}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Vínculos de serviço</p>
                        <p className="text-white/80 text-sm font-semibold tabular-nums mt-0.5">{resultado?.vinculos_servico ?? 0}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Vínculos c/ fonte financeira</p>
                        <p className="text-white/80 text-sm font-semibold tabular-nums mt-0.5">{resultado?.vinculos_financeiros ?? 0}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider" title="Vínculos ainda sem uma fonte de dados financeiros associada (ex.: aguardando régua de bônus da Shopee)">
                            Vínculos sem fonte financeira
                        </p>
                        <p className="text-white/80 text-sm font-semibold tabular-nums mt-0.5">{resultado?.vinculos_sem_fonte_financeira ?? 0}</p>
                    </div>
                </div>
            )}

            {/* Ajuste 2026-07-13 · link pra carteira do profissional. Coloca a
                nota em contexto: quais empresas geraram a média, faturamento,
                margem etc. */}
            {user?.id && (
                <div className="relative mt-4 pt-4 border-t border-white/[0.06]">
                    <Link
                        href={route('portfolio.show', user.id)}
                        className="inline-flex items-center gap-2 text-sm text-white/70 hover:text-white transition-colors group"
                    >
                        <Briefcase size={14} className="text-ecf-yellow" />
                        <span>Detalhes sobre as empresas da carteira</span>
                        <ChevronRight size={14} className="text-white/40 group-hover:text-white/80 group-hover:translate-x-0.5 transition-all" />
                    </Link>
                </div>
            )}
        </div>
    );
}

// ─── Página principal ────────────────────────────────────────────────────
export default function PerformanceShow({
    user,
    resultado = {},
    mes_selecionado,
    modo = null,
    meses_disponiveis = [],
    periodo = null,
    bonus = null,
    nps_window = null,
    empresas_invalidadas = 0,
    empresas_score = [],
    empresas_score_resumo = { entraram: 0, nao_entraram: 0 },
    tem_detalhe_empresas = false,
}) {
    const c = resultado?.componentes ?? {};
    const semCarteira = resultado?.sem_carteira === true;
    const isClosed = periodo?.is_closed === true;

    // UIEM-01/D-04 — sublabel do card de margem sem jargão de API. A frase
    // em pontos percentuais (D-05) só entra quando o shadow já rodou para
    // esta competência; payload antigo/mês em curso cai no texto legado
    // sozinho (D-11), nunca em `undefined` na tela.
    const fraseMargemPp = fraseVarMargemPp(c?.var_margem_pp);
    const margemSublabel = fraseMargemPp ? `${MARGEM_CARD_SUBLABEL} ${fraseMargemPp}` : MARGEM_CARD_SUBLABEL;

    // Modo ativo do segmento (mesmo contrato do ranking).
    const modoAtivo = modo === 'bonus_atual' ? 'bonus_atual' : (isClosed ? 'mes_fechado' : 'em_curso');

    // Sublabel do card de NPS — reflete o modelo de 2 meses (janela M+1).
    const npsSublabel = modoAtivo === 'em_curso'
        ? 'Mês em curso ainda sem NPS — piso 1,0 até a coleta do mês seguinte.'
        : (nps_window?.collection_month
            ? `Média das respostas coletadas em ${mesExtenso(nps_window.collection_month)} (escala 1-5).`
            : 'Média das respostas NPS da competência (escala 1-5).');

    const irPara = (params) => router.get(
        route('performance.show', user.id), params,
        { preserveState: false, preserveScroll: true },
    );
    const trocarMes = (ym) => irPara({ mes: ym });

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

                    {/* Seletor de mês — único controle de período desde 2026-08-05.
                        O toggle "Em curso / Bônus atual / Mês fechado" saiu: ele
                        mudava o período por atalho e deixava ambíguo QUAL mês
                        estava na tela. O dropdown é explícito e marca o mês em
                        curso com seu próprio rótulo. */}
                    <div className="flex items-center gap-2 flex-wrap">
                        {meses_disponiveis.length > 0 && (
                            <select
                                value={String(mes_selecionado ?? '').slice(0, 7)}
                                onChange={(e) => trocarMes(e.target.value)}
                                title="Selecionar mês"
                                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 cursor-pointer capitalize"
                            >
                                {meses_disponiveis.map(m => (
                                    <option key={m} value={m}>{mesExtenso(m)}</option>
                                ))}
                            </select>
                        )}
                    </div>
                </div>

                {/* Banner de contexto de período (Fase 2 · mesmo modelo do ranking) */}
                {!semCarteira && (
                    modoAtivo === 'bonus_atual' ? (
                        <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] p-4 flex items-start gap-3">
                            <div className="w-8 h-8 rounded-lg bg-ecf-yellow/15 flex items-center justify-center shrink-0">
                                <Trophy size={16} className="text-ecf-yellow" />
                            </div>
                            <div className="text-sm">
                                <div className="text-ecf-yellow font-semibold">
                                    Bônus atual — competência {mesExtenso(bonus?.competence_month)}
                                    {bonus?.payment_month && <>, pago em {mesExtenso(bonus.payment_month)}</>}
                                </div>
                                <div className="text-white/60 text-xs mt-1 leading-relaxed">
                                    Usa os resultados financeiros de <span className="text-white font-medium">{mesExtenso(bonus?.competence_month)}</span> comparados
                                    com a janela anterior de mesmo tamanho, e o <span className="text-white font-medium">NPS coletado em {mesExtenso(nps_window?.collection_month)}</span>
                                    {nps_window?.status === 'coletando' ? ' (ainda em coleta)' : ' (coleta encerrada)'}.
                                    O NPS pertence ao mês de coleta seguinte à competência financeira.
                                </div>
                            </div>
                        </div>
                    ) : modoAtivo === 'em_curso' ? (
                        <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex items-start gap-3">
                            <div className="w-8 h-8 rounded-lg bg-amber-500/15 flex items-center justify-center shrink-0">
                                <Calendar size={16} className="text-amber-300" />
                            </div>
                            <div className="text-sm">
                                <div className="text-amber-200 font-semibold">
                                    Mês em curso — acompanhamento operacional
                                </div>
                                <div className="text-amber-100/70 text-xs mt-1 leading-relaxed">
                                    Não é fechamento de bônus. As variações comparam
                                    <span className="text-white font-medium"> dia 1 até a data disponível </span>
                                    contra o <span className="text-white font-medium">mesmo intervalo do mês anterior</span>.
                                    O NPS deste mês só é coletado no mês seguinte — até lá entra com <span className="text-white font-medium">piso 1,0</span>.
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-white/[0.1] bg-white/[0.03] p-4 flex items-start gap-3">
                            <div className="w-8 h-8 rounded-lg bg-white/[0.06] flex items-center justify-center shrink-0">
                                <Calendar size={16} className="text-white/50" />
                            </div>
                            <div className="text-sm">
                                <div className="text-white/80 font-semibold">
                                    Mês fechado — {mesExtenso(String(mes_selecionado).slice(0, 7))}
                                </div>
                                <div className="text-white/50 text-xs mt-1 leading-relaxed">
                                    Comparação contra a janela anterior oficial (mesmo tamanho).
                                    {periodo?.baseline_start && periodo?.baseline_end && (
                                        <> Baseline: {fmtDia(periodo.baseline_start)}–{fmtDia(periodo.baseline_end)}.</>
                                    )}
                                </div>
                            </div>
                        </div>
                    )
                )}

                {/* Empresas invalidadas para bônus nesta competência (item 3/4) */}
                {!semCarteira && empresas_invalidadas > 0 && (
                    <div className="rounded-xl border border-red-500/25 bg-red-500/[0.06] px-4 py-2.5 flex items-center gap-2 text-sm text-red-200">
                        <Info size={14} className="text-red-300 shrink-0" />
                        <span>
                            <strong>{empresas_invalidadas}</strong> empresa{empresas_invalidadas > 1 ? 's' : ''} invalidada{empresas_invalidadas > 1 ? 's' : ''} para bônus nesta competência —
                            removida{empresas_invalidadas > 1 ? 's' : ''} do financeiro e do NPS desta nota.
                        </span>
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
                                valor={formatNota(c.nps_medio)}
                                sublabel={npsSublabel}
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
                                titulo={MARGEM_CARD_TITULO}
                                valor={formatPercent(c.var_margem_pct)}
                                sublabel={margemSublabel}
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
                            <FaixaBonusCard resultado={resultado} user={user} />
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

                        {/* Empresas da carteira (UIEM-02) — lista com nota e três
                            componentes em competência fechada com detalhe gravado
                            (D-01/D-06/D-07); aviso explícito quando não há detalhe
                            para não sumir silenciosamente (D-03/D-11). */}
                        <div>
                            <h2 className="text-white text-lg font-display font-bold flex items-center gap-2 mb-3">
                                <Briefcase size={18} className="text-ecf-yellow" />
                                Empresas da carteira
                            </h2>

                            {tem_detalhe_empresas ? (
                                <EmpresasScoreTabela linhas={empresas_score} resumo={empresas_score_resumo} />
                            ) : (
                                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 flex items-start gap-3">
                                    <Info size={16} className="text-white/40 shrink-0 mt-0.5" />
                                    <div>
                                        <p className="text-white/80 text-sm font-semibold">{AVISO_SEM_DETALHE_TITULO}</p>
                                        <p className="text-white/50 text-xs mt-1 leading-relaxed">
                                            {modoAtivo === 'em_curso'
                                                ? AVISO_SEM_DETALHE_EM_CURSO
                                                : avisoSemDetalheFechado(mesExtenso(String(mes_selecionado ?? '').slice(0, 7)))}
                                        </p>
                                    </div>
                                </div>
                            )}
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
                        A nota final é a média direta dos parâmetros disponíveis (NPS · variação do faturamento · variação da margem). O
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
