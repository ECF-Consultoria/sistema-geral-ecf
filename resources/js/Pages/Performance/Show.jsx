import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import {
    ArrowLeft, Star, TrendingUp, TrendingDown, Coins,
    Trophy, Sparkles, UserX, BookOpen, Info, Briefcase, ChevronRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import EmpresasScoreTabela from '@/Components/Desempenho/EmpresasScoreTabela';
import PeriodoBanner from '@/Components/Desempenho/PeriodoBanner';
import {
    MARGEM_CARD_TITULO, MARGEM_CARD_SUBLABEL,
    AVISO_SEM_DETALHE_TITULO, AVISO_SEM_DETALHE_EM_CURSO, avisoSemDetalheFechado,
    resumoCarteiraLinha, fmtPp,
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
 *     meses_disponiveis: Array<{value: string, label: string, em_curso: bool}> }
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
//
// 2026-08-06 — o valor principal passou a ser o PONTO (régua 1-5), com a % de
// variação rebaixada a linha pequena. A unidade de decisão do time é ponto: a
// nota final é a média dos pontos, e a tela mostrava "+34,1%" como protagonista
// sem que a ligação com a nota aparecesse em lugar nenhum. Mesma inversão que o
// ranking já tinha recebido em `PontosToneCell` (Performance/Index.jsx).
function ParametroCard({ icone: Icone, titulo, valor, sublabel, accentColor = 'ecf-yellow', emBreve = false, trendDir, destaque = null }) {
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
                <span className="text-white/30 text-sm">/ 5,00</span>
                {trendDir === 'up'   && <TrendingUp   size={18} className="text-emerald-300" />}
                {trendDir === 'down' && <TrendingDown size={18} className="text-rose-300" />}
                {emBreve && (
                    <span className="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/30">
                        Em breve
                    </span>
                )}
            </div>

            {/* A % / p.p. que era o valor principal até 2026-08-06 — continua na
                tela, agora como coadjuvante do ponto que decide a nota. */}
            {destaque && (
                <p className="relative mt-1.5 text-white/60 text-[13px] tabular-nums font-medium">
                    {destaque}
                </p>
            )}

            {sublabel && (
                <p className="relative mt-auto text-white/50 text-xs pt-3">
                    {sublabel}
                </p>
            )}
        </div>
    );
}

// ─── Card destaque Faixa de bônus ────────────────────────────────────────
// `mesDetalhe` — 'YYYY-MM' quando a tela está num mês que NÃO é o corrente,
// senão null. Vem por PROP: o link daqui roda no escopo DESTE componente e não
// enxerga nada declarado no `PerformanceShow` (ver o incidente registrado em
// tests/js/estrutura-performance-ranking.test.js).
function FaixaBonusCard({ resultado, user, mesDetalhe = null }) {
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

    // Linha única de composição da carteira (2026-08-06): substitui o grid de 4
    // tiles daqui MAIS o card "Info carteira" que repetia baseline/carteira logo
    // abaixo — dois blocos sobre o mesmo assunto. Os 4 números seguem acessíveis
    // no tooltip, mesmo padrão do ranking.
    const carteiraLinha = resumoCarteiraLinha({
        comBaseline: resultado?.empresas_com_baseline,
        carteira:    resultado?.empresas_carteira ?? resultado?.empresas_unicas,
        vinculos:    resultado?.vinculos_servico,
        semFonte:    resultado?.vinculos_sem_fonte_financeira,
    });

    return (
        <div className={cn(
            'relative overflow-hidden rounded-2xl border p-6 md:col-span-3',
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

            {/* Composição da carteira + saídas da tela, numa faixa só. */}
            <div className="relative mt-4 pt-4 border-t border-white/[0.06] flex items-center justify-between gap-4 flex-wrap">
                {temMetadados && (
                    <p className="text-white/50 text-xs tabular-nums cursor-help" title={carteiraLinha.titulo}>
                        {carteiraLinha.texto}
                    </p>
                )}

                <div className="flex items-center gap-4 flex-wrap ml-auto">
                    <Link
                        href="/manual/desempenho-bonificacao"
                        className="inline-flex items-center gap-1.5 text-ecf-yellow text-xs font-semibold hover:underline"
                    >
                        <BookOpen size={12} />
                        Como calculamos?
                    </Link>

                    {/* O link para a carteira sobrevive porque o destino tem o que
                        esta tela NÃO tem: faturamento em R$, ADS/TACoS, vínculos de
                        serviço por empresa e conexão ML. O rótulo diz isso — antes
                        prometia "detalhes sobre as empresas", que é justamente o que
                        a tabela logo abaixo já entrega, e o clique frustrava. */}
                    {user?.id && (
                        <Link
                            href={route('portfolio.show', mesDetalhe ? { user: user.id, mes: mesDetalhe } : { user: user.id })}
                            className="inline-flex items-center gap-2 text-sm text-white/70 hover:text-white transition-colors group"
                        >
                            <Briefcase size={14} className="text-ecf-yellow" />
                            <span>Ver operação da carteira (faturamento, ADS, serviços)</span>
                            <ChevronRight size={14} className="text-white/40 group-hover:text-white/80 group-hover:translate-x-0.5 transition-all" />
                        </Link>
                    )}
                </div>
            </div>
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
    // Pontos por indicador (régua 1-5) — o que decide a nota e, desde
    // 2026-08-06, o valor principal dos cards.
    const p = resultado?.pontos_componentes ?? {};
    const semCarteira = resultado?.sem_carteira === true;
    const isClosed = periodo?.is_closed === true;

    // UIEM-01 — sublabel do card de margem sem jargão de API. A frase em
    // pontos percentuais que era concatenada aqui saiu em 2026-08-06: a
    // variação em p.p. virou a linha de destaque do próprio card, e repeti-la
    // em prosa logo abaixo dizia a mesma coisa três vezes.
    const margemSublabel = MARGEM_CARD_SUBLABEL;

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

    // 2026-08-07 — o mês visto aqui acompanha TODA saída desta tela: o "Ranking"
    // (volta pro /performance no mesmo mês, fechando o ida-e-volta com o dropdown
    // de lá) e o "Ver operação da carteira" (que abre /admin/users/{id}/portfolio,
    // cujo controller já aceita ?mes=YYYY-MM pelo mesmo contrato).
    //
    // null no mês corrente: `?mes=` do mês em curso resolveria pelo ramo `YYYY-MM`
    // do MetricPeriodResolver em vez do `current_month` (mode=operational), e as
    // duas telas de destino trocariam de modo sem o usuário ter pedido.
    //
    // `mes_selecionado` chega como 'YYYY-MM-DD' aqui (toDateString no controller),
    // ao contrário do ranking, que manda 'YYYY-MM' — daí o slice.
    const mesDetalhe = (!periodo?.is_current_month && mes_selecionado)
        ? String(mes_selecionado).slice(0, 7)
        : null;

    const voltarAoRanking = () => router.visit(
        route('performance.index', mesDetalhe ? { mes: mesDetalhe } : {}),
    );

    return (
        <AppLayout title={`Desempenho — ${user?.name ?? 'Analista'}`}>
            <div className="space-y-6 max-w-6xl mx-auto">

                {/* Breadcrumb + Header + Toggle de mês */}
                <div className="flex items-start justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={voltarAoRanking}
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
                                {/* Aceita os dois formatos: objeto {value,label,em_curso}
                                    (contrato atual, alinhado às demais telas) e string
                                    'YYYY-MM' (formato antigo — snapshot de payload em
                                    cache ainda pode chegar assim logo após o deploy). */}
                                {meses_disponiveis.map((m) => {
                                    const value = typeof m === 'string' ? m : m.value;
                                    const label = typeof m === 'string' ? mesExtenso(m) : m.label;
                                    const emCurso = typeof m === 'string' ? false : m.em_curso;

                                    return (
                                        <option key={value} value={value}>
                                            {label}{emCurso ? ' (em curso)' : ''}
                                        </option>
                                    );
                                })}
                            </select>
                        )}
                    </div>
                </div>

                {/* Contexto de período — uma linha, explicação no tooltip
                    (2026-08-06). O parágrafo longo empurrava a nota para baixo da
                    dobra e era o mesmo texto repetido em três telas. */}
                {!semCarteira && (
                    <PeriodoBanner
                        modo={modoAtivo}
                        mesLabel={mesExtenso(String(mes_selecionado ?? '').slice(0, 7))}
                        resumo={
                            modoAtivo === 'bonus_atual'
                                ? `competência ${mesExtenso(bonus?.competence_month)}${bonus?.payment_month ? `, pago em ${mesExtenso(bonus.payment_month)}` : ''}`
                                : modoAtivo === 'em_curso'
                                    ? 'NPS entra com piso 1,0 até a coleta do mês seguinte'
                                    : (periodo?.baseline_start && periodo?.baseline_end
                                        ? `baseline ${fmtDia(periodo.baseline_start)}–${fmtDia(periodo.baseline_end)}`
                                        : null)
                        }
                    />
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
                        {/* 3 CARDS DE PARÂMETROS — em PONTOS (a unidade que decide a nota).
                            O card "Absenteísmo" saiu em 2026-08-06: exibia "—" com selo
                            "Em breve" desde a criação da tela, sem fonte de dados definida
                            — ocupava 1/4 da grade para não informar nada. Volta quando a
                            fonte existir. */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {/* ATENÇÃO: o ponto vem de `pontos_componentes.nps`, NÃO de
                                `componentes.nps_medio`. São números diferentes — o médio é
                                a média das notas de NPS, o ponto é a média dos PONTOS por
                                loja, e é este que entra na conta do card de bônus abaixo.
                                Usar o médio fazia o card não fechar com a própria conta
                                exibida logo ao lado. */}
                            <ParametroCard
                                icone={Star}
                                titulo="NPS"
                                valor={formatNota(p.nps)}
                                destaque={c.nps_medio != null ? `NPS médio ${formatNota(c.nps_medio)}` : null}
                                sublabel={npsSublabel}
                                accentColor="ecf-yellow"
                            />

                            <ParametroCard
                                icone={c.var_faturamento_pct != null && c.var_faturamento_pct >= 0 ? TrendingUp : TrendingDown}
                                titulo="Faturamento"
                                valor={formatNota(p.faturamento)}
                                destaque={c.var_faturamento_pct != null ? `${formatPercent(c.var_faturamento_pct)} vs janela anterior` : null}
                                sublabel="Média dos pontos de faturamento das empresas da carteira"
                                accentColor="emerald"
                                trendDir={c.var_faturamento_pct != null ? (c.var_faturamento_pct >= 0 ? 'up' : 'down') : null}
                            />

                            <ParametroCard
                                icone={Coins}
                                titulo={MARGEM_CARD_TITULO}
                                valor={formatNota(p.margem)}
                                destaque={c.var_margem_pp != null ? `${fmtPp(c.var_margem_pp)} p.p. de margem` : null}
                                sublabel={margemSublabel}
                                accentColor="blue"
                                trendDir={c.var_margem_pp != null ? (c.var_margem_pp >= 0 ? 'up' : 'down') : null}
                            />

                            {/* Faixa de bônus — ocupa a linha inteira abaixo dos 3 cards */}
                            <FaixaBonusCard resultado={resultado} user={user} mesDetalhe={mesDetalhe} />
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
                        Cada indicador vira <strong className="text-white/80">pontos</strong> pela régua de 1 a 5, empresa por
                        empresa; o ponto do card é a média das empresas da carteira, e a nota final é a média dos pontos
                        disponíveis — é a conta que aparece abaixo da nota. A faixa de bônus é configurável pelo admin —
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
