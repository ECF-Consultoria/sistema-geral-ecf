import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    LayoutDashboard, Search, Plus,
    DollarSign, TrendingUp, CheckCircle2, Target, MessageSquare,
    Filter, Columns3, MessageCircleOff, TargetIcon,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
// Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS na carteira
import NpsPendingWidget from '@/Components/Nps/NpsPendingWidget';

/**
 * Dashboard Performance da Carteira — analistas/estrategistas.
 *
 * Adaptado do prototype `dashboard-performance-ui-proposta.html`:
 * design tokens do sistema ECF (ecf-yellow em vez de laranja/vermelho,
 * ecf-card / ecf-bg em dark mode), wrapper AppLayout com sidebar oficial,
 * widgets são cards clicáveis que linkam pras páginas correspondentes.
 *
 * Fase 1 (mock): valores hardcoded do backend. Fase 2: integração real
 * via PortfolioScoreService + AdmanMetric + NpsSurvey + Goal.
 */

// ═══════════════════════════════════════════════════════════════
// Helpers de formatação (compactos — pt-BR)
// ═══════════════════════════════════════════════════════════════

const fmtBRLCompact = (n) => {
    if (n == null) return '—';
    if (Math.abs(n) >= 1_000_000) return `R$ ${(n / 1_000_000).toFixed(2)}M`;
    if (Math.abs(n) >= 1_000) return `R$ ${(n / 1_000).toFixed(0)}K`;
    return `R$ ${n.toLocaleString('pt-BR')}`;
};

// Ajuste 2026-07-09: escala v2 é 1-5 (nota_final do DesempenhoScoreService).
// A cor/label vem da FAIXA_BONUS quando disponível (slug do BonusFaixa) ou do
// fallback por threshold da nota. Preservamos a assinatura scoreClasse(score)
// mas o `score` agora é a nota_final 1-5 (não 0-100).
const scoreClasse = (nota, faixaSlug = null) => {
    // Faixa vinda do backend (BonusFaixa) tem prioridade sobre threshold interno.
    if (faixaSlug === 'maximo')        return { cor: 'emerald', label: 'Máximo' };
    if (faixaSlug === 'intermediario') return { cor: 'lime',    label: 'Intermediário' };
    if (faixaSlug === 'basico')        return { cor: 'amber',   label: 'Básico' };
    if (faixaSlug === 'sem_bonus')     return { cor: 'rose',    label: 'Sem bônus' };
    // Fallback por threshold da nota (1-5) — matches faixas seed.
    const n = Number(nota ?? 0);
    if (n >= 5.00) return { cor: 'emerald', label: 'Máximo' };
    if (n >= 4.50) return { cor: 'lime',    label: 'Intermediário' };
    if (n >= 4.00) return { cor: 'amber',   label: 'Básico' };
    return              { cor: 'rose',    label: 'Sem bônus' };
};

// Status → cor de chip
const statusClasse = {
    saudavel: { classes: 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300', label: 'Saudável' },
    atencao:  { classes: 'bg-amber-500/15 border-amber-500/30 text-amber-300',       label: 'Atenção' },
    critico:  { classes: 'bg-rose-500/15 border-rose-500/30 text-rose-300',           label: 'Crítico' },
};

// Phase 73 Plan 03 (SC#1) — sem classificação legada de 3 buckets (herança do
// NPS 0-10 clássico). Backend (Plan 73-01) removeu o campo `classe` do payload
// `nps.respostas[]` e passou a enviar `nota` como float 2 casas (escala v15 1-5).
// Cor por threshold direto: >=4 positiva (verde), <=3 negativa (vermelho), null cinza.
function corPorNota(nota) {
    if (nota == null) return 'bg-white/[0.03] border-white/[0.08] text-white/60';
    return nota >= 4
        ? 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300'
        : 'bg-rose-500/15 border-rose-500/30 text-rose-300';
}

const iconeMeta = { dollar: DollarSign, check: CheckCircle2, trend: TrendingUp };

// ═══════════════════════════════════════════════════════════════
// Sub-componentes
// ═══════════════════════════════════════════════════════════════

// Barra de progresso amarela ECF
function ProgressBar({ percent, size = 'md' }) {
    const h = size === 'sm' ? 'h-1.5' : 'h-2';
    return (
        <div className={cn('w-full bg-white/[0.06] rounded-full overflow-hidden', h)}>
            <div
                className="h-full bg-gradient-to-r from-ecf-yellow to-yellow-300 rounded-full transition-all"
                style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
            />
        </div>
    );
}

// Logo Mercado Livre (SVG oficial da pasta /public/images/)
function MlBadge({ size = 18 }) {
    return (
        <img
            src="/images/mercado-livre-87.svg"
            alt="Conectada ao Mercado Livre"
            title="Conectada ao Mercado Livre"
            className="inline-block flex-shrink-0"
            style={{ width: size, height: size }}
        />
    );
}

// Velocímetro/gauge do card Score.
// Ajuste 2026-07-09: recebe `nota` na escala 1-5 (v2). Internamente converte para
// 0-100 (nota × 20) para preservar o visual do velocímetro sem mudar o SVG.
function Speedometer({ nota, faixaSlug = null }) {
    const { cor } = scoreClasse(nota, faixaSlug);
    const strokeMap = {
        emerald: '#10b981',
        lime:    '#84cc16',
        amber:   '#f59e0b',
        rose:    '#e11d48',
    };
    // 1-5 → 0-100 pra manter o velocímetro visual. Clamp defensivo.
    const score = Math.min(100, Math.max(0, Number(nota ?? 0) * 20));
    const angulo = -90 + (score / 100) * 180;
    const dashLen = 166 * (score / 100);

    return (
        <svg
            viewBox="0 0 210 130"
            aria-hidden="true"
            className="absolute -right-8 -bottom-6 w-[210px] h-[130px] pointer-events-none opacity-90"
        >
            {/* arco de fundo */}
            <path d="M35 105a70 70 0 0 1 140 0" fill="none" stroke="rgba(255,255,255,.08)" strokeWidth="18" strokeLinecap="round"/>
            {/* arco colorido proporcional ao score */}
            <path
                d="M35 105a70 70 0 0 1 140 0"
                fill="none"
                stroke={strokeMap[cor]}
                strokeWidth="18"
                strokeLinecap="round"
                strokeDasharray={`${dashLen} 220`}
                style={{ transition: 'stroke-dasharray 0.5s ease' }}
            />
            {/* ponteiro */}
            <line
                x1="105" y1="105" x2="105" y2="43"
                stroke="rgba(255,255,255,.9)"
                strokeWidth="4"
                strokeLinecap="round"
                style={{
                    transformOrigin: '105px 105px',
                    transform: `rotate(${angulo}deg)`,
                    transition: 'transform 0.5s ease',
                }}
            />
            <circle cx="105" cy="105" r="7" fill="rgba(255,255,255,.9)"/>
        </svg>
    );
}

// Gráfico de área verde no fundo do card Crescimento — confinado à faixa
// inferior baixa e com opacidade reduzida pra não competir com o texto.
function GrowthBackground() {
    return (
        <>
            <svg
                viewBox="0 0 360 60"
                aria-hidden="true"
                preserveAspectRatio="none"
                className="absolute inset-x-0 bottom-0 w-full h-[54px] pointer-events-none opacity-45"
            >
                <defs>
                    <linearGradient id="growthArea" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stopColor="#10b981" stopOpacity=".5"/>
                        <stop offset="1" stopColor="#10b981" stopOpacity="0"/>
                    </linearGradient>
                </defs>
                <path
                    d="M0 46 C30 40 36 26 68 30 C96 34 99 18 132 20 C161 22 163 8 198 6 C237 4 240 26 272 24 C306 23 321 12 360 15 L360 60 L0 60 Z"
                    fill="url(#growthArea)"
                />
                <path
                    d="M0 46 C30 40 36 26 68 30 C96 34 99 18 132 20 C161 22 163 8 198 6 C237 4 240 26 272 24 C306 23 321 12 360 15"
                    fill="none"
                    stroke="#10b981"
                    strokeWidth="2.2"
                />
            </svg>
            {/* Overlay pra escurecer o topo do gráfico (garante contraste com o texto) */}
            <div className="absolute inset-x-0 bottom-0 h-[54px] bg-gradient-to-t from-transparent via-ecf-card/60 to-ecf-card pointer-events-none" />
        </>
    );
}

// ═══════════════════════════════════════════════════════════════
// Widget NPS Heatmap — Sentiment Grid (2026-07-09)
//
// Uma linha por empresa (últimos 6 meses de dados); uma coluna por mês.
// Cada célula: média das notas NPS do mês (escala 1-5). Intensidade da
// cor cresce com a nota — baixa = cinza escuro, alta = laranja/amarelo ECF.
// ═══════════════════════════════════════════════════════════════

// Mapa de cor por nota — degradê visual, notas altas mais vivas.
// Segue a mesma paleta usada no restante do dashboard (yellows/oranges
// ECF pra evitar dissonância com o resto da interface).
function corDaCelula(nota) {
    if (nota == null) {
        return { bg: 'rgba(255,255,255,0.03)', border: 'rgba(255,255,255,0.06)', text: 'rgba(255,255,255,0.30)' };
    }
    const n = Number(nota);
    // Rampa 1-5 mapeada em 5 níveis de laranja/amarelo (ECF brand).
    if (n >= 4.5)  return { bg: 'rgba(250,204,21,0.85)', border: 'rgba(250,204,21,0.7)', text: '#1a1a00' }; // yellow-400
    if (n >= 4.0)  return { bg: 'rgba(251,146,60,0.75)', border: 'rgba(251,146,60,0.6)', text: '#1a0500' }; // orange-400
    if (n >= 3.5)  return { bg: 'rgba(249,115,22,0.60)', border: 'rgba(249,115,22,0.4)', text: '#ffffff' }; // orange-500
    if (n >= 2.5)  return { bg: 'rgba(234,88,12,0.45)',  border: 'rgba(234,88,12,0.3)',  text: '#ffffff' }; // orange-600
    if (n >= 1.0)  return { bg: 'rgba(180,60,20,0.30)',  border: 'rgba(180,60,20,0.2)',  text: '#ffcfb0' }; // darker orange
    return              { bg: 'rgba(255,255,255,0.03)', border: 'rgba(255,255,255,0.06)', text: 'rgba(255,255,255,0.30)' };
}

function NpsHeatmapWidget({ nps }) {
    const heatmap = nps?.heatmap ?? { meses: [], empresas: [] };
    const meses    = heatmap.meses ?? [];
    const empresas = heatmap.empresas ?? [];
    const media    = nps?.media ?? null;
    const semDados = empresas.length === 0 || empresas.every(e => Object.keys(e.notas ?? {}).length === 0);

    return (
        <Link
            href={route('nps.index')}
            className="group bg-ecf-card border border-white/[0.08] rounded-xl overflow-hidden hover:border-orange-500/40 transition-colors flex flex-col"
        >
            <div className="px-5 py-4 border-b border-white/[0.06] flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <MessageSquare size={16} className="text-orange-400" />
                    <h3 className="text-white text-sm font-bold">NPS · Últimos 6 meses</h3>
                </div>
                {media !== null && (
                    <span className="text-xs px-2 py-0.5 rounded-full bg-orange-500/15 border border-orange-500/30 text-orange-200 font-semibold">
                        Média {Number(media).toFixed(2)}
                    </span>
                )}
            </div>

            {semDados ? (
                <div className="p-8 flex flex-col items-center justify-center text-center gap-2 min-h-[220px]">
                    <MessageCircleOff size={28} className="text-white/20" />
                    <p className="text-white/60 text-sm font-semibold">Sem respostas de NPS ainda</p>
                    <p className="text-white/35 text-xs max-w-[240px]">
                        Quando os clientes responderem à pesquisa, as notas aparecem aqui por mês.
                    </p>
                </div>
            ) : (
                <div className="p-4 space-y-3 overflow-x-auto">
                    <div className="min-w-fit">
                        {/* Header dos meses */}
                        <div className="grid grid-cols-[minmax(140px,180px)_repeat(6,minmax(56px,1fr))] gap-1.5">
                            <div />
                            {meses.map((m) => (
                                <div
                                    key={m.chave}
                                    className="text-center text-white/50 text-[10px] uppercase tracking-wider font-semibold py-1"
                                >
                                    {m.label}
                                </div>
                            ))}
                        </div>

                        {/* Linhas por empresa */}
                        <div className="space-y-1.5 mt-1">
                            {empresas.map((e) => (
                                <div
                                    key={e.id}
                                    className="grid grid-cols-[minmax(140px,180px)_repeat(6,minmax(56px,1fr))] gap-1.5 items-center"
                                >
                                    <div
                                        className="text-white/80 text-[12px] font-medium truncate pr-2"
                                        title={e.name}
                                    >
                                        {e.name}
                                    </div>
                                    {meses.map((m) => {
                                        const nota = e.notas?.[m.chave] ?? null;
                                        const cor  = corDaCelula(nota);
                                        return (
                                            <div
                                                key={m.chave}
                                                className="h-9 rounded-md flex items-center justify-center text-[12px] font-bold tabular-nums transition-transform hover:scale-110"
                                                style={{
                                                    backgroundColor: cor.bg,
                                                    borderWidth: '1px',
                                                    borderStyle: 'solid',
                                                    borderColor: cor.border,
                                                    color: cor.text,
                                                }}
                                                title={
                                                    nota != null
                                                        ? `${e.name} · ${m.label}: ${Number(nota).toFixed(2)}`
                                                        : `${e.name} · ${m.label}: sem resposta`
                                                }
                                            >
                                                {nota != null ? Number(nota).toFixed(1) : '—'}
                                            </div>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>

                        {/* Legenda */}
                        <div className="pt-3 mt-2 border-t border-white/[0.05] flex items-center gap-3 text-[10px] text-white/50">
                            <span className="font-semibold uppercase tracking-wider">Nota baixa</span>
                            <div className="flex gap-0.5">
                                {[1.5, 2.5, 3.5, 4.0, 4.5].map((n) => {
                                    const c = corDaCelula(n);
                                    return (
                                        <div
                                            key={n}
                                            className="w-6 h-3 rounded-sm"
                                            style={{ backgroundColor: c.bg, border: `1px solid ${c.border}` }}
                                        />
                                    );
                                })}
                            </div>
                            <span className="font-semibold uppercase tracking-wider">Nota alta</span>
                        </div>
                    </div>
                </div>
            )}
        </Link>
    );
}

// ═══════════════════════════════════════════════════════════════
// Página principal
// ═══════════════════════════════════════════════════════════════

export default function DashboardCarteira({ pessoa, periodo, kpis, nps, metas, empresas, nps_pendentes = [] }) {
    // Phase 72 Plan 03 v15.0 — Guard defensivo pra prop nps_pendentes (Plan 72-02 injection)
    const npsPendentesList = nps_pendentes ?? [];
    const [busca, setBusca] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [colunas, setColunas] = useState({
        faturamento: true, meta: true, crescimento: true,
        nps: true, acao: true,
    });
    const [showColunas, setShowColunas] = useState(false);

    const empresasFiltradas = useMemo(() => {
        const termo = busca.trim().toLowerCase();
        return empresas.filter((e) => {
            const matchBusca = !termo || e.nome.toLowerCase().includes(termo) || (e.acao || '').toLowerCase().includes(termo);
            const matchStatus = statusFilter === 'all' || e.status === statusFilter;
            return matchBusca && matchStatus;
        });
    }, [empresas, busca, statusFilter]);

    // Ajuste 2026-07-09: backend v2 envia nota_final (1-5) + faixa_bonus (slug).
    // Antes usava kpis.score (0-100) que não existe mais → widget quebrava.
    const notaFinal   = kpis?.nota_final ?? null;
    const faixaSlug   = kpis?.faixa_bonus ?? null;
    const { cor: scoreCor, label: scoreLabel } = scoreClasse(notaFinal, faixaSlug);
    const chipScoreClasses = {
        emerald: 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300',
        lime:    'bg-lime-500/15 border-lime-500/30 text-lime-300',
        amber:   'bg-amber-500/15 border-amber-500/30 text-amber-300',
        rose:    'bg-rose-500/15 border-rose-500/30 text-rose-300',
    }[scoreCor];

    return (
        <AppLayout title="Dashboard">
            <Head title={`Dashboard — ${pessoa.nome}`} />

            <div className="p-6 max-w-7xl mx-auto space-y-6">
                {/* ═══ HEADER ═══════════════════════════════════════ */}
                <div className="flex items-center justify-between gap-4 pb-4 border-b border-white/[0.06]">
                    <div className="flex items-center gap-3">
                        {/* Avatar com iniciais */}
                        <div className="w-11 h-11 rounded-lg bg-gradient-to-br from-ecf-yellow/20 to-yellow-500/5 border border-ecf-yellow/30 flex items-center justify-center text-ecf-yellow font-black text-sm">
                            {pessoa.iniciais}
                        </div>
                        <div>
                            <h1 className="text-white text-xl font-display font-bold leading-none">{pessoa.nome}</h1>
                            <p className="text-white/50 text-sm mt-1">{pessoa.funcao}</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {pessoa.role_key === 'mentor' && (
                            <Link
                                href={route('goals.index')}
                                className="h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-yellow-300 transition-colors inline-flex items-center gap-2 text-xs font-bold"
                            >
                                <Plus size={14} />
                                Nova meta
                            </Link>
                        )}
                    </div>
                </div>

                {/* ═══ 3 KPI CARDS (grid) ═════════════════════════════ */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {/* Card 1 — Faturamento total (sem link — só indicador) */}
                    <div className="relative overflow-hidden rounded-xl bg-ecf-card border border-white/[0.08] p-5 min-h-[160px] flex flex-col justify-between">
                        {/* Glow decorativo yellow ECF */}
                        <div className="absolute -top-16 -right-16 w-52 h-52 bg-ecf-yellow/[0.08] rounded-full blur-3xl pointer-events-none" />
                        {/* Grid pattern sutil */}
                        <div
                            className="absolute inset-0 opacity-30 pointer-events-none"
                            style={{
                                backgroundImage: 'linear-gradient(90deg, rgba(255,230,0,.06) 1px, transparent 1px), linear-gradient(180deg, rgba(255,230,0,.04) 1px, transparent 1px)',
                                backgroundSize: '34px 34px',
                            }}
                        />
                        <div className="relative">
                            <span className="text-[10px] uppercase tracking-wider text-white/60 font-bold">
                                Faturamento total · últimos 30 dias
                            </span>
                            <div className="flex items-baseline gap-3 mt-3 flex-wrap">
                                <strong className="text-4xl font-black text-white tabular-nums leading-none">
                                    {fmtBRLCompact(kpis.faturamento_total)}
                                </strong>
                                <span className="text-xs px-2 py-0.5 rounded-full bg-white/[0.06] border border-white/[0.08] text-white/70 font-semibold">
                                    {kpis.empresas_em_carteira} empresas
                                </span>
                            </div>
                        </div>
                        <p className="relative text-xs text-white/50">
                            {kpis.empresas_conectadas_ml} empresas conectadas ao Mercado Livre.
                        </p>
                    </div>

                    {/* Card 2 — Crescimento vs anterior (link → performance) */}
                    <Link
                        href={route('performance.index')}
                        className="group relative overflow-hidden rounded-xl bg-ecf-card border border-white/[0.08] p-5 min-h-[160px] flex flex-col justify-between hover:border-emerald-500/40 transition-all"
                    >
                        <GrowthBackground />
                        <div className="relative">
                            <span className="text-[10px] uppercase tracking-wider text-white/60 font-bold">
                                Crescimento vs anterior
                            </span>
                            <div className="flex items-baseline gap-3 mt-3 flex-wrap">
                                {kpis.crescimento_percent !== null ? (
                                    <>
                                        <strong className={cn(
                                            'text-4xl font-black tabular-nums leading-none',
                                            kpis.crescimento_percent >= 0 ? 'text-white' : 'text-rose-300'
                                        )}>
                                            {kpis.crescimento_percent >= 0 ? '+' : ''}{kpis.crescimento_percent}%
                                        </strong>
                                        <span className={cn(
                                            'text-xs px-2 py-0.5 rounded-full border font-semibold',
                                            kpis.crescimento_delta_valor >= 0
                                                ? 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300'
                                                : 'bg-rose-500/15 border-rose-500/30 text-rose-300'
                                        )}>
                                            {kpis.crescimento_delta_valor >= 0 ? '+' : ''}{fmtBRLCompact(kpis.crescimento_delta_valor)}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-2xl font-bold text-white/40">Sem base comparativa</span>
                                )}
                            </div>
                        </div>
                        <p className="relative text-xs text-white/50">
                            {kpis.crescimento_mediana !== null
                                ? <>Comparado aos 30 dias anteriores. Mediana por empresa: {kpis.crescimento_mediana >= 0 ? '+' : ''}{kpis.crescimento_mediana}%.</>
                                : 'Base insuficiente pra comparar com o período anterior.'}
                        </p>
                    </Link>

                    {/* Card 3 — Pontuação (link → performance) */}
                    <Link
                        href={route('performance.index')}
                        className="group relative overflow-hidden rounded-xl bg-ecf-card border border-white/[0.08] p-5 min-h-[160px] flex flex-col justify-between hover:border-ecf-yellow/40 transition-all"
                    >
                        <Speedometer nota={notaFinal} faixaSlug={faixaSlug} />
                        <div className="relative">
                            <span className="text-[10px] uppercase tracking-wider text-white/60 font-bold">
                                Pontuação
                            </span>
                            <div className="flex items-baseline gap-3 mt-3 flex-wrap">
                                <strong className="text-4xl font-black text-white tabular-nums leading-none">
                                    {notaFinal != null ? Number(notaFinal).toFixed(2) : '—'}
                                </strong>
                                <span className="text-lg text-white/40 tabular-nums">/ 5,00</span>
                                <span className={cn('text-xs px-2 py-0.5 rounded-full border font-semibold', chipScoreClasses)}>
                                    {scoreLabel}
                                </span>
                            </div>
                        </div>
                        <p className="relative text-xs text-white/50 max-w-[65%]">
                            Média de NPS, variação de faturamento e variação de margem.
                        </p>
                    </Link>
                </div>

                {/* ═══ NPS + METAS (grid 2 cols) ═════════════════════ */}
                <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.15fr)] gap-4">
                    {/* Widget NPS (link → /nps) */}
                    {/* Widget NPS Heatmap — redesign 2026-07-09.
                        Linhas = empresas, Colunas = últimos 6 meses, Células = média
                        das notas do NPS no mês. Intensidade da cor proporcional à
                        nota (baixa cinza, alta laranja/amarelo ECF).
                        Sem dados → célula fica escura vazia. */}
                    <NpsHeatmapWidget nps={nps} />


                    {/* Widget Metas (link → /goals) */}
                    <Link
                        href={route('goals.index')}
                        className="group bg-ecf-card border border-white/[0.08] rounded-xl overflow-hidden hover:border-ecf-yellow/40 transition-colors flex flex-col"
                    >
                        <div className="px-5 py-4 border-b border-white/[0.06] flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <Target size={16} className="text-ecf-yellow" />
                                <h3 className="text-white text-sm font-bold">Progresso das metas</h3>
                            </div>
                            <span className="text-xs px-2 py-1 rounded-full bg-white/[0.03] border border-white/[0.06] text-white/60 font-semibold">
                                Ver todas
                            </span>
                        </div>
                        {metas && metas.length > 0 ? (
                            <div className="p-4 space-y-3">
                                {metas.map((m, i) => {
                                    const Icone = iconeMeta[m.icone] ?? Target;
                                    return (
                                        <div key={i} className="flex items-center gap-3 p-3 rounded-lg bg-white/[0.02] border border-white/[0.04]">
                                            <span className="w-10 h-10 rounded-lg bg-ecf-yellow/15 border border-ecf-yellow/30 text-ecf-yellow flex items-center justify-center flex-shrink-0">
                                                <Icone size={17} />
                                            </span>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-baseline justify-between gap-2 mb-1.5">
                                                    <strong className="text-white text-sm font-bold truncate">{m.nome}</strong>
                                                    <span className="text-white/50 text-xs whitespace-nowrap">{m.atual} / {m.objetivo}</span>
                                                </div>
                                                <ProgressBar percent={m.percent} size="sm" />
                                            </div>
                                            <b className="text-white text-sm font-bold tabular-nums w-10 text-right">{m.percent}%</b>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="p-8 flex flex-col items-center justify-center text-center gap-2 min-h-[220px]">
                                <TargetIcon size={28} className="text-white/20" />
                                <p className="text-white/70 text-sm font-semibold">Nenhuma meta atribuída</p>
                                <p className="text-white/40 text-xs max-w-[240px]">
                                    {pessoa.role_key === 'mentor'
                                        ? 'Clique em "Nova meta" no topo pra atribuir a primeira meta a uma empresa da carteira.'
                                        : 'Assim que o estrategista atribuir metas às empresas da sua carteira, elas aparecem aqui.'}
                                </p>
                            </div>
                        )}
                    </Link>
                </div>

                {/* ═══ NPS PENDENTES (Phase 72 Plan 03 v15.0) ═══════════════════
                    Widget novo — mostra empresas da carteira que ainda não responderam
                    o NPS deste mês. Complementa o widget NPS existente (que mostra as
                    últimas respostas) — este mostra quem está faltando. Empty state
                    tratado dentro do próprio widget. */}
                <NpsPendingWidget pendentes={npsPendentesList} />

                {/* ═══ TABELA EMPRESAS EM CARTEIRA ═══════════════════ */}
                <div className="bg-ecf-card border border-white/[0.08] rounded-xl overflow-hidden">
                    {/* Header */}
                    <div className="px-5 py-4 border-b border-white/[0.06] flex items-center justify-between gap-3 flex-wrap">
                        <div className="flex items-center gap-2">
                            <LayoutDashboard size={16} className="text-white/60" />
                            <h3 className="text-white text-sm font-bold">Empresas em carteira</h3>
                        </div>
                        <span className="text-white/50 text-xs">
                            {empresasFiltradas.length} {empresasFiltradas.length === 1 ? 'empresa listada' : 'empresas listadas'}
                        </span>
                    </div>

                    {/* Toolbar (busca + filtros + colunas) */}
                    <div className="px-5 py-3 border-b border-white/[0.06] flex items-center gap-3 flex-wrap">
                        <div className="relative flex-1 min-w-[220px]">
                            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                            <input
                                type="search"
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                placeholder="Buscar por empresa ou ação"
                                className="w-full h-9 pl-9 pr-3 rounded-lg bg-white/[0.03] border border-white/[0.08] text-white text-sm placeholder-white/30 focus:outline-none focus:border-ecf-yellow/40 focus:ring-1 focus:ring-ecf-yellow/40"
                            />
                        </div>

                        <div className="relative">
                            <Filter size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                            <select
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="h-9 pl-8 pr-8 rounded-lg bg-white/[0.03] border border-white/[0.08] text-white/80 text-xs font-semibold appearance-none focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                            >
                                <option value="all">Todos os status</option>
                                <option value="saudavel">Saudável</option>
                                <option value="atencao">Atenção</option>
                                <option value="critico">Crítico</option>
                            </select>
                        </div>

                        <div className="relative">
                            <button
                                onClick={() => setShowColunas((v) => !v)}
                                className="h-9 px-3 rounded-lg bg-white/[0.03] border border-white/[0.08] text-white/80 text-xs font-semibold inline-flex items-center gap-2 hover:bg-white/[0.06] transition-colors"
                            >
                                <Columns3 size={13} />
                                Colunas
                            </button>
                            {showColunas && (
                                <div className="absolute top-full right-0 mt-2 w-56 p-3 rounded-lg bg-ecf-card border border-white/[0.1] shadow-2xl z-20 space-y-2">
                                    {[
                                        { key: 'faturamento', label: 'Faturamento', locked: true },
                                        { key: 'meta',        label: 'Meta',        locked: true },
                                        { key: 'crescimento', label: 'Crescimento', locked: true },
                                        { key: 'nps',         label: 'NPS' },
                                        { key: 'acao',        label: 'Ação recomendada' },
                                    ].map(({ key, label, locked }) => (
                                        <label
                                            key={key}
                                            className={cn(
                                                'flex items-center gap-2 text-xs cursor-pointer',
                                                locked ? 'text-white/40' : 'text-white/70 hover:text-white'
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={colunas[key]}
                                                disabled={locked}
                                                onChange={(e) => setColunas((c) => ({ ...c, [key]: e.target.checked }))}
                                                className="accent-ecf-yellow"
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Tabela (desktop) */}
                    <div className="overflow-x-auto hidden md:block">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-[10px] uppercase tracking-wider text-white/50 border-b border-white/[0.06]">
                                    <th className="px-5 py-3 font-bold">Empresa</th>
                                    {colunas.faturamento && <th className="px-3 py-3 font-bold text-right">Faturamento</th>}
                                    {colunas.meta        && <th className="px-3 py-3 font-bold text-right">Meta</th>}
                                    {colunas.crescimento && <th className="px-3 py-3 font-bold text-right">Crescimento</th>}
                                    {colunas.nps         && <th className="px-3 py-3 font-bold text-right">NPS</th>}
                                    {colunas.acao        && <th className="px-3 py-3 font-bold">Ação recomendada</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {empresasFiltradas.map((e) => {
                                    return (
                                        <tr key={e.nome} className="border-b border-white/[0.04] hover:bg-white/[0.02] transition-colors">
                                            <td className="px-5 py-3">
                                                <div className="flex items-center gap-3">
                                                    <span className="w-9 h-9 rounded-lg bg-white/[0.05] border border-white/[0.06] text-white/60 font-black flex items-center justify-center flex-shrink-0">
                                                        {e.nome.charAt(0)}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-white font-semibold truncate">{e.nome}</span>
                                                            {e.ml && <MlBadge />}
                                                        </div>
                                                        {e.nota && <span className="text-white/40 text-[11px] block mt-0.5 truncate">{e.nota}</span>}
                                                    </div>
                                                </div>
                                            </td>
                                            {colunas.faturamento && <td className="px-3 py-3 text-right text-white font-semibold tabular-nums">{fmtBRLCompact(e.faturamento)}</td>}
                                            {colunas.meta && (
                                                <td className="px-3 py-3 text-right">
                                                    {e.meta !== null ? (
                                                        <div className="inline-flex flex-col items-end gap-1">
                                                            <span className="w-24"><ProgressBar percent={e.meta} size="sm" /></span>
                                                            <span className="text-white/50 text-[11px] font-semibold">{e.meta}%</span>
                                                        </div>
                                                    ) : <span className="text-white/25">—</span>}
                                                </td>
                                            )}
                                            {colunas.crescimento && (
                                                <td className="px-3 py-3 text-right">
                                                    {e.crescimento !== null ? (
                                                        <span className={cn(
                                                            'text-[10px] px-2 py-0.5 rounded-full border font-semibold whitespace-nowrap',
                                                            e.crescimento >= 15 && 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300',
                                                            e.crescimento < 15 && e.crescimento >= 0 && 'bg-amber-500/15 border-amber-500/30 text-amber-300',
                                                            e.crescimento < 0 && 'bg-rose-500/15 border-rose-500/30 text-rose-300',
                                                        )}>
                                                            {e.crescimento >= 0 ? '+' : ''}{e.crescimento}%
                                                        </span>
                                                    ) : <span className="text-white/25">—</span>}
                                                </td>
                                            )}
                                            {colunas.nps         && <td className="px-3 py-3 text-right text-white/80 tabular-nums">{e.nps ?? <span className="text-white/25">—</span>}</td>}
                                            {colunas.acao        && <td className="px-3 py-3 text-white/70">{e.acao}</td>}
                                        </tr>
                                    );
                                })}
                                {empresasFiltradas.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-center py-10 text-white/40 text-sm">
                                            Nenhuma empresa encontrada com os filtros aplicados.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Cards mobile */}
                    <div className="md:hidden p-4 space-y-3">
                        {empresasFiltradas.map((e) => {
                            return (
                                <div key={e.nome} className="p-3 rounded-lg bg-white/[0.02] border border-white/[0.06] space-y-3">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <span className="text-white font-bold text-sm truncate">{e.nome}</span>
                                        {e.ml && <MlBadge />}
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span className="text-white/40 block">Faturamento</span>
                                            <b className="text-white">{fmtBRLCompact(e.faturamento)}</b>
                                        </div>
                                        <div>
                                            <span className="text-white/40 block">Meta</span>
                                            <b className="text-white">{e.meta !== null ? `${e.meta}%` : '—'}</b>
                                        </div>
                                        <div>
                                            <span className="text-white/40 block">Crescimento</span>
                                            {e.crescimento !== null ? (
                                                <b className={cn(
                                                    e.crescimento >= 15 && 'text-emerald-300',
                                                    e.crescimento < 15 && e.crescimento >= 0 && 'text-amber-300',
                                                    e.crescimento < 0 && 'text-rose-300',
                                                )}>
                                                    {e.crescimento >= 0 ? '+' : ''}{e.crescimento}%
                                                </b>
                                            ) : <b className="text-white/25">—</b>}
                                        </div>
                                        <div>
                                            <span className="text-white/40 block">Ação</span>
                                            <b className="text-white/80 text-[11px]">{e.acao}</b>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

            </div>
        </AppLayout>
    );
}
