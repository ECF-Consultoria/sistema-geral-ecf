import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { TicketIndividualChart } from '@/Components/TicketMedioChart';
import {
    AreaChart, Area, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    RadialBarChart, RadialBar, PolarAngleAxis, RadarChart, Radar, PolarGrid, PolarRadiusAxis,
} from 'recharts';
import {
    CheckCircle, MessageSquare, ShoppingCart,
    CheckCheck, AlertTriangle, DollarSign, Award,
} from 'lucide-react';
import { cn, formatCurrencyCompact } from '@/lib/utils';

const MESES_PT = {
    '01':'Janeiro','02':'Fevereiro','03':'Março','04':'Abril',
    '05':'Maio','06':'Junho','07':'Julho','08':'Agosto',
    '09':'Setembro','10':'Outubro','11':'Novembro','12':'Dezembro',
};

function nomeMes(ym) {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    return `${MESES_PT[m]} / ${y}`;
}

function fmt(n) { return Number(n ?? 0).toLocaleString('pt-BR'); }
function fmtDec(n) { return Number(n ?? 0).toFixed(1).replace('.', ','); }
function pct0(n) { return n === null || n === undefined ? '—' : `${Number(n).toFixed(0)}%`; }

// ── Lookup tables de classificação (espelhadas de Portfolio/Show.jsx) ─────────
const CLASSIF_LABEL = { excelente:'Excelente', bom:'Bom', atencao:'Atenção', critico:'Crítico' };
const CLASSIF_CLS   = { excelente:'text-emerald-300', bom:'text-sky-300', atencao:'text-amber-300', critico:'text-red-300' };
const CLASSIF_BG    = {
    excelente:'bg-emerald-500/10 border-emerald-500/30',
    bom:'bg-sky-500/10 border-sky-500/30',
    atencao:'bg-amber-500/10 border-amber-500/30',
    critico:'bg-red-500/10 border-red-500/30',
};
const SCORE_COLOR   = { excelente:'#10b981', bom:'#38bdf8', atencao:'#f59e0b', critico:'#ef4444' };

// Explicações curtas para o tooltip de cada eixo/minimétrica do publicador.
const METRIC_HELP = {
    score: 'Score 0-100 ponderado: 35% Meta + 25% Produtividade + 20% Pontualidade + 10% Conversão + 10% Qualidade. Eixos sem dado têm o peso redistribuído.',
    meta: 'Atingimento da meta de anúncios do mês (feito ÷ meta).',
    produtividade: 'Volume de anúncios em relação à meta (0%→0, 100%→80, ≥130%→100 pts).',
    pontualidade: 'SKUs entregues sem atraso nas empresas onde você é o responsável.',
    conversao: 'Anúncios que geraram pelo menos uma venda no mês (vendidos ÷ feitos).',
    qualidade: 'Anúncios sem problema (60%) + feedbacks do líder resolvidos (40%).',
};

const chartCfg = {
    tooltip: { contentStyle: { background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12 }, labelStyle: { color:'#9ba0aa' } },
    axis:    { tick: { fill:'#6a6f79', fontSize:11 } },
};

const PRIORIDADE_STYLE = {
    '1 Urgente': 'bg-red-500/20 text-red-400 border-red-500/30',
    '2 Alto':    'bg-orange-500/20 text-orange-400 border-orange-500/30',
    '3 Média':   'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    '4 Baixa':   'bg-white/5 text-white/40 border-white/10',
};

// ── KPI grande ────────────────────────────────────────────────────────────────
function KpiCard({ title, value, sub, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text:'text-ecf-yellow', bg:'bg-ecf-yellow/10', border:'border-ecf-yellow/20' },
        green:  { text:'text-emerald-400', bg:'bg-emerald-500/10', border:'border-emerald-500/20' },
        orange: { text:'text-orange-400', bg:'bg-orange-500/10', border:'border-orange-500/20' },
        purple: { text:'text-purple-400', bg:'bg-purple-500/10', border:'border-purple-500/20' },
        blue:   { text:'text-blue-400',   bg:'bg-blue-500/10',   border:'border-blue-500/20'   },
        teal:   { text:'text-teal-400',   bg:'bg-teal-500/10',   border:'border-teal-500/20'   },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-2xl p-5">
            <div className="flex items-center justify-between mb-3">
                <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center border', c.bg, c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <p className={cn('font-display font-extrabold text-3xl tracking-tight', c.text)}>{value}</p>
            {sub && <p className="text-white/30 text-xs mt-1">{sub}</p>}
        </div>
    );
}

// ── Mini-métrica de detalhe (espelhada de Show.jsx) ──────────────────────────
function MiniMetric({ label, value, sub, help }) {
    return (
        <div className="rounded-lg bg-white/[0.04] border border-white/[0.06] px-3 py-2" title={help}>
            <div className="text-white/45 text-[10px] uppercase tracking-wide flex items-center gap-1">
                {label}
                {help && <span className="text-white/30 text-[9px] cursor-help">ⓘ</span>}
            </div>
            <div className="text-white/90 text-base font-bold tabular-nums leading-tight mt-0.5">{value}</div>
            <div className="text-white/35 text-[10px] mt-0.5">{sub}</div>
        </div>
    );
}

// ── Section de desempenho: Radial (score) + Radar (5 eixos) ──────────────────
function PerformanceSection({ data }) {
    const pts = data.pontos_categoria ?? {};
    const m   = data.metricas ?? {};
    const cor = SCORE_COLOR[data.classificacao] ?? '#3b82f6';

    const dimensoes = [
        { dim: 'Meta',          valor: pts.meta?.valor ?? 0,          bruto: m.meta?.pct,                  sufixo: '%' },
        { dim: 'Produtividade', valor: pts.produtividade?.valor ?? 0, bruto: m.produtividade?.pct,         sufixo: '%' },
        { dim: 'Pontualidade',  valor: pts.pontualidade?.valor ?? 0,  bruto: m.pontualidade?.pct_no_prazo, sufixo: '%' },
        { dim: 'Conversão',     valor: pts.conversao?.valor ?? 0,     bruto: m.conversao?.pct,             sufixo: '%' },
        { dim: 'Qualidade',     valor: pts.qualidade?.valor ?? 0,     bruto: m.qualidade?.pct_sem_problema, sufixo: '%' },
    ];
    const radialData = [{ name: 'Score', value: data.score, fill: cor }];
    const semDados = !data.score;

    return (
        <div className={cn('rounded-2xl border p-4 md:p-5 mb-6', CLASSIF_BG[data.classificacao] ?? 'border-white/[0.06] bg-ecf-card/60')}>
            <div className="flex items-center gap-2 mb-3">
                <Award size={16} className={CLASSIF_CLS[data.classificacao]} />
                <h3 className="text-white text-sm font-semibold cursor-help" title={METRIC_HELP.score}>
                    Desempenho do Publicador <span className="text-white/30 text-[10px]">ⓘ</span>
                </h3>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-4">
                {/* Radial score */}
                <div className="relative rounded-xl bg-white/[0.02] border border-white/[0.04] p-3 flex items-center justify-center">
                    <div className="h-56 w-full relative">
                        <ResponsiveContainer width="100%" height="100%">
                            <RadialBarChart cx="50%" cy="50%" innerRadius="70%" outerRadius="100%" barSize={18}
                                data={radialData} startAngle={90} endAngle={-270}>
                                <PolarAngleAxis type="number" domain={[0, 100]} tick={false} />
                                <RadialBar dataKey="value" cornerRadius={10} background={{ fill: 'rgba(255,255,255,0.05)' }} />
                            </RadialBarChart>
                        </ResponsiveContainer>
                        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                            <div className={cn('text-5xl font-extrabold tabular-nums leading-none', CLASSIF_CLS[data.classificacao])}>
                                {Math.round(data.score)}
                            </div>
                            <div className="text-white/40 text-[11px] mt-0.5">de 100 pts</div>
                            <div className={cn('text-[12px] font-semibold mt-1.5', CLASSIF_CLS[data.classificacao])}>
                                {CLASSIF_LABEL[data.classificacao]}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Radar das 5 dimensões */}
                <div className="rounded-xl bg-white/[0.02] border border-white/[0.04] p-3">
                    <div className="h-56 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <RadarChart data={dimensoes} margin={{ top: 10, right: 30, bottom: 10, left: 30 }}>
                                <PolarGrid stroke="rgba(255,255,255,0.08)" />
                                <PolarAngleAxis dataKey="dim" tick={{ fill: 'rgba(255,255,255,0.55)', fontSize: 10 }} />
                                <PolarRadiusAxis domain={[0, 100]} tick={false} axisLine={false} />
                                <Radar name="Pontuação" dataKey="valor" stroke={cor} fill={cor} fillOpacity={0.35} dot={{ r: 3, fill: cor }} />
                                <Tooltip
                                    cursor={false}
                                    contentStyle={{ backgroundColor:'rgba(15,16,22,0.95)', border:'1px solid rgba(255,255,255,0.12)', borderRadius:8, fontSize:12 }}
                                    formatter={(value, name, props) => {
                                        const bruto = props?.payload?.bruto;
                                        const suf   = props?.payload?.sufixo;
                                        if (bruto === null || bruto === undefined) return ['sem dado', 'Valor'];
                                        return [`${typeof bruto === 'number' ? bruto.toFixed(1) : bruto}${suf} (${Math.round(value)} pts)`, 'Valor'];
                                    }}
                                    labelStyle={{ color: 'rgba(255,255,255,0.9)' }}
                                />
                            </RadarChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            </div>

            {/* Cards detalhados (substituem Cresc.ajustado/Crescendo/Meta/Recuperação/NPS da Carteira) */}
            <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mt-3">
                <MiniMetric
                    label="Atingimento da meta"
                    value={pct0(m.meta?.pct)}
                    sub={`${fmt(m.meta?.feito)}/${fmt(m.meta?.alvo)} anúncios`}
                    help={METRIC_HELP.meta}
                />
                <MiniMetric
                    label="Produtividade"
                    value={pts.produtividade?.valor !== null && pts.produtividade?.valor !== undefined ? `${Math.round(pts.produtividade.valor)} pts` : '—'}
                    sub={m.produtividade?.pct !== null && m.produtividade?.pct !== undefined ? `${m.produtividade.pct.toFixed(0)}% da meta` : 'sem meta'}
                    help={METRIC_HELP.produtividade}
                />
                <MiniMetric
                    label="Entregas com atraso"
                    value={`${fmt(m.pontualidade?.atrasados)}/${fmt(m.pontualidade?.total_skus)}`}
                    sub={m.pontualidade?.pct_no_prazo !== null && m.pontualidade?.pct_no_prazo !== undefined ? `${m.pontualidade.pct_no_prazo.toFixed(0)}% no prazo` : 'sem SKUs'}
                    help={METRIC_HELP.pontualidade}
                />
                <MiniMetric
                    label="Conversão"
                    value={pct0(m.conversao?.pct)}
                    sub={`${fmt(m.conversao?.vendidos)}/${fmt(m.conversao?.feito)} vendidos`}
                    help={METRIC_HELP.conversao}
                />
                <MiniMetric
                    label="Qualidade"
                    value={pct0(m.qualidade?.pct_sem_problema)}
                    sub={m.qualidade?.pct_feedbacks_resolvidos !== null && m.qualidade?.pct_feedbacks_resolvidos !== undefined ? `${m.qualidade.pct_feedbacks_resolvidos.toFixed(0)}% feedbacks ok` : 'sem feedbacks'}
                    help={METRIC_HELP.qualidade}
                />
            </div>

            {semDados && (
                <p className="mt-3 text-white/40 text-[11px]">
                    <AlertTriangle size={10} className="inline mr-1" />
                    Sem dados suficientes no período — o score aparece conforme você publica e recebe vendas/feedbacks.
                </p>
            )}
        </div>
    );
}

// ── Tooltip do gráfico de faturamento (só o realizado acumulado) ─────────────
function FaturamentoTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const ponto = payload[0]?.payload ?? {};
    const dateLabel = label ? label.slice(8, 10) + '/' + label.slice(5, 7) : '';
    return (
        <div className="rounded-lg border border-white/15 bg-ecf-bg/95 backdrop-blur px-3 py-2 text-[12px] shadow-xl">
            <div className="text-white/90 font-semibold mb-1">{dateLabel}</div>
            <div className="flex items-center justify-between gap-4">
                <span className="text-emerald-300">Faturamento acum.</span>
                <span className="text-white/90 tabular-nums">{formatCurrencyCompact(ponto.realizado)}</span>
            </div>
        </div>
    );
}

function ProblemasSection({ problemas, onResolverAnuncio }) {
    if (!problemas) return null;
    const { empresas, anuncios } = problemas;
    if (!empresas?.length && !anuncios?.length) return null;
    const total = (empresas?.length ?? 0) + (anuncios?.length ?? 0);

    return (
        <div className="rounded-2xl border border-red-500/25 bg-red-500/[0.03] p-5 mb-6">
            <div className="flex items-center gap-2 mb-4">
                <div className="w-7 h-7 rounded-lg bg-red-500/15 border border-red-500/30 flex items-center justify-center shrink-0">
                    <AlertTriangle size={13} className="text-red-400" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Problemas em Aberto</p>
                    <p className="text-red-400/70 text-[11px] mt-0.5">{total} item{total !== 1 ? 's' : ''} precisam de atenção</p>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {empresas?.length > 0 && (
                    <div>
                        <p className="text-white/30 text-[10px] font-semibold tracking-widest uppercase mb-2">
                            Contas ({empresas.length})
                        </p>
                        <div className="space-y-2">
                            {empresas.map(e => (
                                <div key={e.id} className="rounded-xl border border-red-500/20 bg-red-500/5 p-3">
                                    <div className="flex items-center justify-between gap-2 mb-1">
                                        <span className="text-white font-medium text-[13px] truncate">{e.nome}</span>
                                        {e.prioridade && (
                                            <span className={cn('shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded border', PRIORIDADE_STYLE[e.prioridade] ?? PRIORIDADE_STYLE['4 Baixa'])}>
                                                {e.prioridade}
                                            </span>
                                        )}
                                    </div>
                                    {e.nota && (
                                        <p className="text-white/60 text-[12px] mt-0.5">↳ {e.nota}</p>
                                    )}
                                    <p className="text-white/25 text-[10px] mt-1.5">{e.projeto} · {e.em}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {anuncios?.length > 0 && (
                    <div>
                        <p className="text-white/30 text-[10px] font-semibold tracking-widest uppercase mb-2">
                            Anúncios ({anuncios.length})
                        </p>
                        <div className="space-y-2">
                            {anuncios.map(a => (
                                <div key={a.id} className="rounded-xl border border-orange-500/20 bg-orange-500/5 p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 mb-1">
                                                <span className="text-white font-medium text-[13px] truncate">{a.empresa}</span>
                                                <a
                                                    href={`https://produto.mercadolivre.com.br/${a.mlb_code.replace('MLB', 'MLB-')}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="shrink-0 text-purple-400 font-mono text-[11px] hover:text-purple-300 transition-colors"
                                                >
                                                    {a.mlb_code}
                                                </a>
                                            </div>
                                            {a.nota && (
                                                <p className="text-white/60 text-[12px] mt-0.5">↳ {a.nota}</p>
                                            )}
                                            <p className="text-white/25 text-[10px] mt-1.5">Publicado em {a.data_pub} · Marcado em {a.em}</p>
                                        </div>
                                        {onResolverAnuncio && (
                                            <button
                                                onClick={() => onResolverAnuncio(a.id)}
                                                className="shrink-0 flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors"
                                            >
                                                <CheckCheck size={11} /> Marcar resolvido
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function MeuPainel({
    kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses, problemas,
    ticketEvolucao = [], ticketAtual = 0,
    // Fase 38 — Painel do Publicador
    score_publicador = null, faturamento_mes = 0, anuncios_feitos = 0, vendas_mes = 0,
    net_billing_timeseries = [],
}) {
    const k = kpis ?? {};

    const handleMes = (e) => {
        router.get(route('mlb.meu-painel'), { mes: e.target.value }, { preserveState: true });
    };

    function resolverComentario(id) {
        router.patch(route('mlb.resolver', id), {}, { preserveScroll: true });
    }

    function handleResolverAnuncio(id) {
        router.patch(route('mlb.problema', id), {}, { preserveScroll: true });
    }

    const statusColors = { above:'#22c55e', ontrack:'#eab308', below:'#ef4444' };
    const barColor = statusColors[k.status_classe] || '#8b5cf6';
    const temFaturamento = (net_billing_timeseries ?? []).length > 0;

    return (
        <AppLayout title="Painel do Publicador">
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Painel do Publicador</h1>
                    <p className="text-white/40 text-sm mt-0.5">Seu desempenho do mês — score, metas e faturamento</p>
                </div>
                <select
                    value={mesRef}
                    onChange={handleMes}
                    className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    {(meses ?? []).map(m => (
                        <option key={m} value={m}>{nomeMes(m)}</option>
                    ))}
                </select>
            </div>

            {/* KPIs grandes */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <KpiCard
                    title="Faturamento"
                    value={formatCurrencyCompact(faturamento_mes)}
                    sub="anúncios vendidos no mês"
                    icon={DollarSign}
                    color="green"
                />
                <KpiCard
                    title="Anúncios Feitos"
                    value={fmt(anuncios_feitos || k.feito)}
                    sub={`${fmtDec(k.percentual)}% da meta (${fmt(meta)})`}
                    icon={CheckCircle}
                    color="yellow"
                />
                <KpiCard
                    title="Vendas no Mês"
                    value={fmt(vendas_mes || k.vendas)}
                    sub={`${fmtDec(k.conversao_vendas)}% conversão · ${fmt(k.vendas_qty)} peças`}
                    icon={ShoppingCart}
                    color="blue"
                />
            </div>

            {/* Desempenho: score + radar de 5 eixos */}
            {score_publicador && <PerformanceSection data={score_publicador} />}

            {/* Evolução do faturamento */}
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <div className="flex items-center justify-between mb-3">
                    <div>
                        <p className="text-white font-semibold text-sm">Evolução do faturamento</p>
                        <p className="text-white/40 text-[11px] mt-0.5">Realizado acumulado · {nomeMes(mesRef)}</p>
                    </div>
                    <span className="text-emerald-300 font-display font-extrabold text-lg tabular-nums">
                        {formatCurrencyCompact(faturamento_mes)}
                    </span>
                </div>
                {temFaturamento ? (
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={net_billing_timeseries} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
                                <XAxis
                                    dataKey="date"
                                    tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
                                    tickFormatter={(d) => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : ''}
                                    stroke="rgba(255,255,255,0.1)"
                                />
                                <YAxis
                                    tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
                                    tickFormatter={(v) => formatCurrencyCompact(v)}
                                    stroke="rgba(255,255,255,0.1)"
                                    width={52}
                                />
                                <Tooltip content={<FaturamentoTooltip />} />
                                <Line type="monotone" dataKey="realizado" name="Realizado" stroke="#10b981" strokeWidth={2.5} dot={false} activeDot={{ r: 5 }} />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                ) : (
                    <p className="text-white/25 text-sm text-center py-12">Sem faturamento registrado neste período</p>
                )}
            </div>

            {/* Progresso da Meta */}
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <div className="flex items-center justify-between mb-2">
                    <div>
                        <p className="text-white font-semibold text-sm">Progresso da Meta</p>
                        <p className="text-white/40 text-xs mt-0.5">
                            {fmt(k.feito)} de {fmt(k.meta)} anúncios · {fmtDec(k.percentual)}%
                        </p>
                    </div>
                    <span className={cn('px-2.5 py-0.5 rounded-full text-[10px] font-bold border tracking-wide uppercase', {
                        'bg-emerald-500/15 text-emerald-400 border-emerald-500/30': k.status_classe === 'above',
                        'bg-yellow-500/15 text-yellow-400 border-yellow-500/30':    k.status_classe === 'ontrack',
                        'bg-red-500/15 text-red-400 border-red-500/30':             k.status_classe === 'below',
                    })}>
                        {k.status}
                    </span>
                </div>
                <div className="h-3 bg-white/10 rounded-full overflow-hidden mt-3">
                    <div
                        style={{ width:`${Math.min(k.percentual ?? 0, 100)}%`, background: barColor }}
                        className="h-full rounded-full transition-all"
                    />
                </div>
                <div className="flex justify-between text-[11px] text-white/30 mt-1.5">
                    <span>0</span>
                    <span>Projeção: {fmt(k.projecao)}</span>
                    <span>Meta: {fmt(k.meta)}</span>
                </div>
            </div>

            {/* Problemas em aberto */}
            <ProblemasSection problemas={problemas} onResolverAnuncio={handleResolverAnuncio} />

            {/* Evolução diária (publicações) + Top empresas */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">
                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Publicações</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-4">Evolução Diária · {nomeMes(mesRef)}</p>
                    <ResponsiveContainer width="100%" height={200}>
                        <AreaChart data={evolucaoDiaria ?? []} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="gradMeuPainel" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%"  stopColor="#ffe600" stopOpacity={0.45} />
                                    <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="transparent" />
                            <XAxis dataKey="data" {...chartCfg.axis} axisLine={false} tickLine={false} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                            <Tooltip {...chartCfg.tooltip} />
                            <Area type="monotone" dataKey="total" name="Publicações" stroke="#ffe600" strokeWidth={2.5} fill="url(#gradMeuPainel)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>

                <div className="card-ecf rounded-2xl p-5 lg:col-span-2">
                    <p className="text-white font-semibold text-sm mb-4">Top 5 Empresas</p>
                    <div className="space-y-3">
                        {(topEmpresas ?? []).map((e, i) => (
                            <div key={i}>
                                <div className="flex justify-between text-[13px] mb-1">
                                    <span className="text-white/70 truncate max-w-[160px]">{e.empresa}</span>
                                    <span className="text-ecf-yellow font-bold">{fmt(e.total)}</span>
                                </div>
                                <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div
                                        style={{ width:`${((e.total / (topEmpresas[0]?.total || 1)) * 100).toFixed(1)}%`, background:'#8b5cf6' }}
                                        className="h-full rounded-full"
                                    />
                                </div>
                            </div>
                        ))}
                        {(!topEmpresas || topEmpresas.length === 0) && (
                            <p className="text-white/20 text-sm text-center py-6">Sem dados para este mês</p>
                        )}
                    </div>
                </div>
            </div>

            {/* Ticket Médio (seção secundária mantida) */}
            <div className="mb-6">
                <TicketIndividualChart evolucao={ticketEvolucao} ticketAtual={ticketAtual} />
            </div>

            {/* Feedbacks do líder */}
            {feedbacks?.length > 0 && (
                <div className="card-ecf rounded-2xl p-5">
                    <div className="flex items-center gap-2 mb-4">
                        <MessageSquare size={16} className="text-blue-400" />
                        <p className="text-white font-semibold text-sm">Feedbacks do Líder</p>
                        <span className="px-2 py-0.5 rounded-full text-[10px] bg-blue-500/15 text-blue-400 border border-blue-500/30 font-bold">
                            {feedbacks.length}
                        </span>
                    </div>
                    <div className="space-y-3">
                        {feedbacks.map(fb => (
                            <div key={fb.id} className="rounded-xl border border-blue-500/20 bg-blue-500/5 p-3">
                                <div className="flex justify-between items-start mb-1.5">
                                    <span className="text-white font-medium text-[13px]">{fb.empresa}</span>
                                    <a
                                        href={`https://produto.mercadolivre.com.br/${fb.mlb_code.replace('MLB','MLB-')}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-purple-400 font-mono text-[11px] hover:text-purple-300"
                                    >
                                        {fb.mlb_code}
                                    </a>
                                </div>
                                <p className="text-white/70 text-[13px]">💬 {fb.comentario}</p>
                                <div className="flex items-center justify-between mt-1.5">
                                    <p className="text-white/30 text-[11px]">
                                        ↩ {fb.comentario_autor} · {fb.comentario_em}
                                    </p>
                                    <button
                                        onClick={() => resolverComentario(fb.id)}
                                        className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors"
                                    >
                                        <CheckCheck size={10} /> Marcar resolvido
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
