import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { TicketIndividualChart } from '@/Components/TicketMedioChart';
import {
    AreaChart, Area, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    RadialBarChart, RadialBar, PolarAngleAxis, RadarChart, Radar, PolarGrid, PolarRadiusAxis,
} from 'recharts';
import {
    CheckCircle, MessageSquare, ShoppingCart,
    CheckCheck, AlertTriangle, DollarSign, Award, Target, CalendarClock, Save, Lock, Search,
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
function fmtNota(n) { return n === null || n === undefined ? '—' : Number(n).toFixed(2).replace('.', ','); }
function pct0(n) { return n === null || n === undefined ? '—' : `${Number(n).toFixed(0)}%`; }

// ── Plano de Metas do Time de Publicação — faixas de bônus ────────────────────
const FAIXA_LABEL = { sem_bonus:'Sem bônus', base:'Bônus base', intermediario:'Bônus intermediário', maximo:'Bônus máximo' };
const FAIXA_CLS   = { sem_bonus:'text-white/50', base:'text-sky-300', intermediario:'text-violet-300', maximo:'text-emerald-300' };
const FAIXA_BG    = {
    sem_bonus:'bg-white/[0.03] border-white/[0.08]',
    base:'bg-sky-500/10 border-sky-500/30',
    intermediario:'bg-violet-500/10 border-violet-500/30',
    maximo:'bg-emerald-500/10 border-emerald-500/30',
};
const FAIXA_COLOR = { sem_bonus:'#6b7280', base:'#38bdf8', intermediario:'#a78bfa', maximo:'#10b981' };

// Cor da nota 1-5 de cada indicador (vermelho→verde).
const NOTA_COLOR = { 1:'#ef4444', 2:'#f97316', 3:'#eab308', 4:'#38bdf8', 5:'#10b981' };
function notaColor(n) { return n == null ? '#4b5563' : (NOTA_COLOR[Math.round(n)] ?? '#eab308'); }

// Config dos 5 indicadores do plano: rótulo, peso e como formatar o valor apurado.
const INDICADORES = [
    { key:'publicacoes',   label:'Nº de publicações', peso:30,
      valorFmt:(v) => v == null ? '—' : `${fmt(v)}`,        metaFmt:(m) => `cota ${fmt(m)}`,
      regua:'<280 → 1 · 280-319 → 2 · 320 → 3 · 321-369 → 4 · ≥370 → 5' },
    { key:'conversao',     label:'Conversão',          peso:30,
      valorFmt:(v) => v == null ? '—' : `${fmtDec(v)}%`,    metaFmt:(m) => `meta ${fmt(m)}%`,
      regua:'<30% → 1 · 30-39,99% → 3 · ≥40% → 5' },
    { key:'produtividade', label:'Produtividade',      peso:15,
      valorFmt:(v) => v == null ? '—' : `${fmtDec(v)}/dia`, metaFmt:(m) => `ref ${fmt(m)}/dia útil`,
      regua:'≤12 → 1 · 13-15 → 2 · 16 → 3 · 17-19 → 4 · ≥20 → 5' },
    { key:'pontualidade',  label:'Pontualidade',       peso:15,
      valorFmt:(v) => v == null ? '—' : `${fmtDec(v)}%`,    metaFmt:(m) => `meta ${fmt(m)}%`,
      regua:'<70% → 1 · 70-79 → 2 · 80-89 → 3 · 90-96 → 4 · 97-100 → 5' },
    { key:'absenteismo',   label:'Absenteísmo',        peso:10,
      valorFmt:null,                                        metaFmt:() => 'sem faltas',
      regua:'3+ faltas → 1 · 2 → 2 · 1 → 3 · atrasos pontuais → 4 · sem faltas/atrasos → 5' },
];

const METRIC_HELP = {
    score: 'Nota 0-5 ponderada do Plano de Metas: 30% Publicações + 30% Conversão + 15% Produtividade + 15% Pontualidade + 10% Absenteísmo. Indicadores sem dado no mês têm o peso redistribuído.',
    publicacoes: 'Total de anúncios publicados no mês (cota de 320).',
    conversao: 'Anúncios que geraram pelo menos uma venda no mês (vendidos ÷ feitos).',
    produtividade: 'Média de anúncios por dia útil trabalhado (referência 16/dia).',
    pontualidade: 'Anúncios com prazo combinado publicados dentro dele (data ≤ prazo).',
    absenteismo: 'Faltas e atrasos não justificados no mês (registrados pelo líder).',
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

// ── Card de 1 indicador do plano: nota 1-5 + valor apurado + régua ──────────
function IndicadorCard({ cfg, ind }) {
    const nota = ind?.nota ?? null;
    const cor  = notaColor(nota);
    const valorStr = cfg.key === 'absenteismo'
        ? (ind?.registrado
            ? (ind.faltas > 0 ? `${fmt(ind.faltas)} falta${ind.faltas !== 1 ? 's' : ''}` : (ind.atrasos ? 'só atrasos' : 'sem faltas'))
            : 'sem registro')
        : cfg.valorFmt(ind?.valor);
    const meta = cfg.key === 'absenteismo' ? cfg.metaFmt() : cfg.metaFmt(ind?.meta);

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3" title={`${cfg.regua}\n\n${METRIC_HELP[cfg.key]}`}>
            <div className="flex items-center justify-between gap-2">
                <span className="text-white/60 text-[11px] font-semibold uppercase tracking-wide truncate">{cfg.label}</span>
                <span className="text-white/25 text-[10px] tabular-nums">{cfg.peso}%</span>
            </div>
            <div className="flex items-baseline gap-2 mt-1.5">
                <span className="font-display font-extrabold text-2xl tabular-nums leading-none" style={{ color: cor }}>
                    {nota == null ? '—' : nota}
                </span>
                <span className="text-white/30 text-[11px]">/ 5</span>
                <span className="ml-auto text-white/70 text-[13px] font-semibold tabular-nums">{valorStr}</span>
            </div>
            {/* barra de nota 1-5 */}
            <div className="h-1.5 rounded-full bg-white/[0.06] overflow-hidden mt-2">
                <div className="h-full rounded-full" style={{ width: `${nota == null ? 0 : (nota / 5) * 100}%`, backgroundColor: cor }} />
            </div>
            <p className="text-white/30 text-[10px] mt-1.5">{meta}{nota == null ? ' · sem dado (peso redistribuído)' : ''}</p>
        </div>
    );
}

// ── Section do Plano de Metas: nota 0-5 + faixa de bônus + radar + indicadores ─
function PlanoMetasSection({ data }) {
    const faixa = data.faixa ?? 'sem_bonus';
    const cor   = FAIXA_COLOR[faixa] ?? '#6b7280';
    const inds  = data.indicadores ?? {};
    const radialData = [{ name: 'Nota', value: data.score100 ?? 0, fill: cor }];
    const rebaixado  = data.faixa !== data.faixa_por_nota;

    const dimensoes = INDICADORES.map((c) => ({
        dim: c.label.replace('Nº de ', ''),
        valor: inds[c.key]?.nota ?? 0,
        temDado: inds[c.key]?.nota != null,
    }));

    return (
        <div className={cn('rounded-2xl border p-4 md:p-5 mb-6', FAIXA_BG[faixa])}>
            <div className="flex items-center gap-2 mb-3">
                <Award size={16} className={FAIXA_CLS[faixa]} />
                <h3 className="text-white text-sm font-semibold cursor-help" title={METRIC_HELP.score}>
                    Plano de Metas · Nota do mês <span className="text-white/30 text-[10px]">ⓘ</span>
                </h3>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-[240px_1fr] gap-4">
                {/* Radial nota 0-5 + faixa de bônus */}
                <div className="relative rounded-xl bg-white/[0.02] border border-white/[0.04] p-3 flex items-center justify-center">
                    <div className="h-52 w-full relative">
                        <ResponsiveContainer width="100%" height="100%">
                            <RadialBarChart cx="50%" cy="50%" innerRadius="70%" outerRadius="100%" barSize={18}
                                data={radialData} startAngle={90} endAngle={-270}>
                                <PolarAngleAxis type="number" domain={[0, 100]} tick={false} />
                                <RadialBar dataKey="value" cornerRadius={10} background={{ fill: 'rgba(255,255,255,0.05)' }} />
                            </RadialBarChart>
                        </ResponsiveContainer>
                        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                            <div className="text-5xl font-extrabold tabular-nums leading-none" style={{ color: cor }}>
                                {fmtNota(data.nota)}
                            </div>
                            <div className="text-white/40 text-[11px] mt-0.5">de 5,00</div>
                            <div className="text-[12px] font-semibold mt-1.5" style={{ color: cor }}>
                                {FAIXA_LABEL[faixa]}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Radar das 5 notas (0-5) */}
                <div className="rounded-xl bg-white/[0.02] border border-white/[0.04] p-3">
                    <div className="h-52 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <RadarChart data={dimensoes} margin={{ top: 10, right: 30, bottom: 10, left: 30 }}>
                                <PolarGrid stroke="rgba(255,255,255,0.08)" />
                                <PolarAngleAxis dataKey="dim" tick={{ fill: 'rgba(255,255,255,0.55)', fontSize: 10 }} />
                                <PolarRadiusAxis domain={[0, 5]} tick={false} axisLine={false} />
                                <Radar name="Nota" dataKey="valor" stroke={cor} fill={cor} fillOpacity={0.35} dot={{ r: 3, fill: cor }} />
                                <Tooltip
                                    cursor={false}
                                    contentStyle={{ backgroundColor:'rgba(15,16,22,0.95)', border:'1px solid rgba(255,255,255,0.12)', borderRadius:8, fontSize:12 }}
                                    formatter={(value, name, props) => props?.payload?.temDado ? [`nota ${value}`, 'Indicador'] : ['sem dado', 'Indicador']}
                                    labelStyle={{ color: 'rgba(255,255,255,0.9)' }}
                                />
                            </RadarChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            </div>

            {/* 5 indicadores com nota, valor apurado e régua */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2 mt-3">
                {INDICADORES.map((c) => <IndicadorCard key={c.key} cfg={c} ind={inds[c.key]} />)}
            </div>

            {/* Travas de elegibilidade aplicadas (rebaixaram a faixa) */}
            {rebaixado && data.travas?.length > 0 && (
                <div className="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-3 py-2">
                    <p className="text-amber-300 text-[11px] font-semibold flex items-center gap-1.5">
                        <Lock size={11} /> Faixa por nota seria {FAIXA_LABEL[data.faixa_por_nota]} — rebaixada por travas:
                    </p>
                    <ul className="mt-1 space-y-0.5">
                        {data.travas.map((t, i) => (
                            <li key={i} className="text-white/55 text-[11px] pl-4">• {t}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

// ── Card editor de Absenteísmo (líder/gestor) ────────────────────────────────
function AbsenteismoCard({ ind, alvoId, mesRef }) {
    const [faltas, setFaltas]   = useState(ind?.faltas ?? 0);
    const [atrasos, setAtrasos] = useState(ind?.atrasos ?? false);
    const [obs, setObs]         = useState(ind?.observacao ?? '');
    const [salvando, setSalvando] = useState(false);

    const salvar = () => {
        setSalvando(true);
        router.put(route('mlb.absenteismo'), {
            user_id: alvoId, mes: mesRef,
            faltas_nao_justificadas: Number(faltas) || 0,
            atrasos_pontuais: !!atrasos,
            observacao: obs || null,
        }, {
            preserveScroll: true,
            onFinish: () => setSalvando(false),
        });
    };

    const notaPrevista = Number(faltas) >= 3 ? 1 : Number(faltas) === 2 ? 2 : Number(faltas) === 1 ? 3 : (atrasos ? 4 : 5);

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center gap-2 mb-3">
                <CalendarClock size={16} className="text-violet-300" />
                <p className="text-white font-semibold text-sm">Absenteísmo do mês</p>
                <span className="ml-auto text-[11px] text-white/40">{ind?.registrado ? 'registrado' : 'sem registro'}</span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-[140px_1fr] gap-3 items-end">
                <div>
                    <label className="text-white/45 text-[11px] block mb-1">Faltas não justificadas</label>
                    <input type="number" min="0" max="31" value={faltas}
                        onChange={(e) => setFaltas(e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/90 text-sm focus:outline-none" />
                </div>
                <label className="flex items-center gap-2 h-9 text-white/70 text-[13px] cursor-pointer select-none">
                    <input type="checkbox" checked={atrasos} disabled={Number(faltas) > 0}
                        onChange={(e) => setAtrasos(e.target.checked)}
                        className="h-4 w-4 rounded border-white/20 bg-transparent" />
                    Houve pequenos atrasos pontuais (sem faltas)
                </label>
            </div>
            <input type="text" value={obs} onChange={(e) => setObs(e.target.value)} placeholder="Observação (opcional)"
                className="w-full h-9 px-3 mt-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/90 text-sm focus:outline-none" />
            <div className="flex items-center justify-between mt-3">
                <span className="text-[12px] text-white/50">Nota prevista: <span className="font-bold" style={{ color: notaColor(notaPrevista) }}>{notaPrevista}/5</span></span>
                <button onClick={salvar} disabled={salvando}
                    className="inline-flex items-center gap-1.5 px-3 h-9 rounded-lg text-[13px] font-bold border border-violet-500/30 text-violet-200 bg-violet-500/10 hover:bg-violet-500/20 transition-colors disabled:opacity-50">
                    <Save size={13} /> {salvando ? 'Salvando…' : 'Salvar'}
                </button>
            </div>
        </div>
    );
}

// ── Card de Pontualidade: prazos combinados por anúncio (líder edita) ────────
function PrazosCard({ anuncios = [], ind, editavel }) {
    const [busca, setBusca] = useState('');
    const total   = ind?.total ?? 0;
    const noPrazo = ind?.no_prazo ?? 0;

    const setPrazo = (id, prazo) => {
        router.patch(route('mlb.pub.prazo', id), { prazo: prazo || null }, { preserveScroll: true, preserveState: true });
    };

    const lista = useMemo(() => {
        // Publicador (read-only) vê só os anúncios com prazo combinado; o líder
        // vê todos para poder atribuir prazo a qualquer um.
        const base = editavel ? anuncios : anuncios.filter(a => a.prazo != null);
        const q = busca.trim().toLowerCase();
        const arr = q ? base.filter(a => (a.empresa || '').toLowerCase().includes(q) || (a.mlb_code || '').toLowerCase().includes(q)) : base;
        // prioriza anúncios com prazo definido no topo
        return [...arr].sort((a, b) => (b.prazo ? 1 : 0) - (a.prazo ? 1 : 0));
    }, [anuncios, busca, editavel]);

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center gap-2 mb-1">
                <Target size={16} className="text-sky-300" />
                <p className="text-white font-semibold text-sm">Pontualidade · prazos combinados</p>
                <span className="ml-auto text-[12px] text-white/50 tabular-nums">
                    {total > 0 ? `${fmt(noPrazo)}/${fmt(total)} no prazo · ${fmtDec(ind?.valor)}%` : 'nenhum prazo definido'}
                </span>
            </div>
            <p className="text-white/35 text-[11px] mb-3">
                {editavel ? 'Defina o prazo de cada anúncio; publicações até o prazo contam como pontuais.' : 'Anúncios com prazo combinado e sua situação.'}
            </p>

            {anuncios.length > 6 && (
                <div className="relative mb-2">
                    <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                    <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Filtrar por empresa ou MLB…"
                        className="w-full h-8 pl-8 pr-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/90 text-[13px] focus:outline-none" />
                </div>
            )}

            <div className="max-h-72 overflow-y-auto pr-1 space-y-1.5">
                {lista.length === 0 && <p className="text-white/25 text-sm text-center py-6">Nenhum anúncio neste mês</p>}
                {lista.map((a) => {
                    const status = a.prazo == null ? null : a.no_prazo;
                    return (
                        <div key={a.id} className="flex items-center gap-2 rounded-lg border border-white/[0.05] bg-white/[0.02] px-3 py-2">
                            <div className="min-w-0 flex-1">
                                <p className="text-white/85 text-[13px] truncate">{a.empresa}</p>
                                <p className="text-white/35 text-[11px] font-mono">{a.mlb_code} · pub {a.data ? a.data.slice(8,10)+'/'+a.data.slice(5,7) : '—'}</p>
                            </div>
                            {status !== null && (
                                <span className={cn('shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded border',
                                    status ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/30' : 'text-red-300 bg-red-500/10 border-red-500/30')}>
                                    {status ? 'no prazo' : 'atrasado'}
                                </span>
                            )}
                            {editavel ? (
                                <input type="date" defaultValue={a.prazo ?? ''} onChange={(e) => setPrazo(a.id, e.target.value)}
                                    className="shrink-0 h-8 px-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/80 text-[12px] focus:outline-none" />
                            ) : (
                                <span className="shrink-0 text-white/50 text-[12px] tabular-nums w-24 text-right">
                                    {a.prazo ? 'prazo ' + a.prazo.slice(8,10)+'/'+a.prazo.slice(5,7) : 'sem prazo'}
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>
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

// Severidade da pendência (espelha App\Models\Pendencia::SEVERIDADES).
const SEVERIDADE_PEND = {
    bloqueio:   { label: 'Bloqueio',   chip: 'border-red-500/30 bg-red-500/10 text-red-400',       box: 'border-red-500/20 bg-red-500/5' },
    ajuste:     { label: 'Ajuste',     chip: 'border-orange-500/30 bg-orange-500/10 text-orange-400', box: 'border-orange-500/20 bg-orange-500/5' },
    observacao: { label: 'Observação', chip: 'border-blue-500/30 bg-blue-500/10 text-blue-400',    box: 'border-blue-500/20 bg-blue-500/5' },
};

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
                            {anuncios.map(a => {
                                const sev = SEVERIDADE_PEND[a.severidade] ?? SEVERIDADE_PEND.ajuste;
                                const aguardando = a.status === 'corrigida';
                                return (
                                    <div key={a.id} className={cn('rounded-xl border p-3', aguardando ? 'border-amber-500/25 bg-amber-500/[0.04]' : sev.box)}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2 mb-1 flex-wrap">
                                                    <span className="text-white font-medium text-[13px] truncate">{a.empresa}</span>
                                                    <a
                                                        href={`https://produto.mercadolivre.com.br/${String(a.mlb_code ?? '').replace('MLB', 'MLB-')}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="shrink-0 text-purple-400 font-mono text-[11px] hover:text-purple-300 transition-colors"
                                                    >
                                                        {a.mlb_code}
                                                    </a>
                                                    <span className={cn('shrink-0 px-1.5 py-0.5 rounded border text-[10px] font-bold', sev.chip)}>
                                                        {sev.label}
                                                    </span>
                                                    {a.categoria && (
                                                        <span className="shrink-0 text-white/40 text-[10px] px-1.5 py-0.5 rounded bg-white/[0.05]">{a.categoria}</span>
                                                    )}
                                                    {a.idade_dias != null && a.idade_dias > 2 && (
                                                        <span className={cn('shrink-0 px-1.5 py-0.5 rounded border text-[10px] font-bold',
                                                            a.idade_dias > 7 ? 'text-red-400 border-red-500/25 bg-red-500/10' : 'text-orange-400 border-orange-500/25 bg-orange-500/10')}>
                                                            {a.idade_dias}d
                                                        </span>
                                                    )}
                                                </div>
                                                {a.nota && (
                                                    <p className="text-white/60 text-[12px] mt-0.5 whitespace-pre-line">↳ {a.nota}</p>
                                                )}
                                                <p className="text-white/25 text-[10px] mt-1.5">
                                                    Publicado em {a.data_pub} · aberta por {a.aberta_por ?? '—'} em {a.em}
                                                </p>
                                                {aguardando && (
                                                    <p className="text-amber-400/80 text-[11px] mt-1 font-medium">
                                                        Aguardando o líder reconferir
                                                    </p>
                                                )}
                                            </div>
                                            {/* O publicador informa que corrigiu; quem fecha é o líder. */}
                                            {onResolverAnuncio && !aguardando && (
                                                <button
                                                    onClick={() => onResolverAnuncio(a.id)}
                                                    className="shrink-0 flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors"
                                                >
                                                    <CheckCheck size={11} /> Corrigi
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Visão geral: grid de cards (1 por publicador) p/ admin/gestor/líder ──────
// Cada card resume o publicador (score + classificação + KPIs do mês) e abre o
// painel individual ao clicar. Reusa os lookups de classificação do topo.
function VisaoGeralPublicadores({ cards = [], onAbrir }) {
    if (!cards.length) {
        return (
            <div className="card-ecf rounded-2xl p-10 text-center text-white/40">
                Nenhum publicador encontrado.
            </div>
        );
    }
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {cards.map((c) => {
                const cor = FAIXA_COLOR[c.faixa] ?? '#6b7280';
                const metaPct = Math.min(100, Math.max(0, Math.round(c.meta_pct ?? 0)));
                return (
                    <button
                        key={c.id}
                        onClick={() => onAbrir(c.id)}
                        className={cn(
                            'text-left rounded-2xl border p-4 transition hover:border-white/20 hover:bg-white/[0.04]',
                            FAIXA_BG[c.faixa] ?? 'border-white/[0.06] bg-ecf-card/60',
                        )}
                    >
                        <div className="flex items-start justify-between gap-2 mb-3">
                            <p className="text-white font-semibold text-sm leading-tight truncate">{c.nome}</p>
                            <span className={cn('text-[10px] font-semibold uppercase tracking-wide whitespace-nowrap', FAIXA_CLS[c.faixa])}>
                                {FAIXA_LABEL[c.faixa] ?? '—'}
                            </span>
                        </div>
                        <div className="flex items-baseline gap-1 mb-3">
                            <span className="font-display font-extrabold text-3xl tabular-nums" style={{ color: cor }}>
                                {fmtNota(c.nota)}
                            </span>
                            <span className="text-white/30 text-xs">/ 5</span>
                        </div>
                        <div className="mb-3">
                            <div className="flex justify-between text-[10px] text-white/40 mb-1">
                                <span>Meta</span><span>{pct0(c.meta_pct)}</span>
                            </div>
                            <div className="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                <div className="h-full rounded-full" style={{ width: `${metaPct}%`, backgroundColor: cor }} />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-2 text-center">
                            <div>
                                <div className="text-white/90 text-sm font-bold tabular-nums">
                                    {fmt(c.feito)}<span className="text-white/30 text-[10px]">/{fmt(c.meta)}</span>
                                </div>
                                <div className="text-white/35 text-[9px] uppercase tracking-wide">Anúncios</div>
                            </div>
                            <div>
                                <div className="text-white/90 text-sm font-bold tabular-nums">{fmt(c.vendas)}</div>
                                <div className="text-white/35 text-[9px] uppercase tracking-wide">Vendas</div>
                            </div>
                            <div>
                                <div className="text-white/90 text-sm font-bold tabular-nums">{formatCurrencyCompact(c.faturamento)}</div>
                                <div className="text-white/35 text-[9px] uppercase tracking-wide">Fatur.</div>
                            </div>
                        </div>
                        <div className="mt-3 text-[11px] text-ecf-yellow/80">ver painel →</div>
                    </button>
                );
            })}
        </div>
    );
}

export default function MeuPainel({
    kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses, problemas,
    ticketEvolucao = [], ticketAtual = 0,
    // Plano de Metas do Time de Publicação
    plano_metas = null, anuncios_prazo = [], pode_gerir_metas = false, alvo_id = null,
    faturamento_mes = 0, anuncios_feitos = 0, vendas_mes = 0,
    net_billing_timeseries = [],
    // Supervisão — seletor de publicador (admin/gestor/líder); publicador/analista veem o próprio
    publicadores = [], pubFiltro = null, podeFiltrar = false, alvoNome = '',
    // Visão geral: grid de cards de todos os publicadores (admin/gestor/líder sem publicador selecionado)
    visaoGeral = false, cards = [],
}) {
    const k = kpis ?? {};

    // Navega preservando o publicador selecionado (modo supervisão).
    const navegar = (params) => {
        router.get(
            route('mlb.meu-painel'),
            { mes: mesRef, pub: pubFiltro || undefined, ...params },
            { preserveState: true, preserveScroll: true },
        );
    };
    const handleMes = (e) => navegar({ mes: e.target.value });
    const handlePub = (e) => navegar({ pub: e.target.value || undefined });

    function resolverComentario(id) {
        router.patch(route('mlb.resolver', id), {}, { preserveScroll: true });
    }

    // Informa que a pendência foi corrigida. Não fecha a pendência: ela vai para
    // "reconferir" e quem confirma é o líder. Recebe o id da PENDÊNCIA.
    function handleResolverAnuncio(pendenciaId) {
        router.patch(route('mlb.pendencia.corrigida', pendenciaId), {}, { preserveScroll: true });
    }

    const statusColors = { above:'#22c55e', ontrack:'#eab308', below:'#ef4444' };
    const barColor = statusColors[k.status_classe] || '#8b5cf6';
    const temFaturamento = (net_billing_timeseries ?? []).length > 0;

    // Cabeçalho compartilhado (título + seletores) entre a visão geral e o painel individual.
    const subtitulo = visaoGeral
        ? `Visão geral da equipe de publicação · ${nomeMes(mesRef)}`
        : (podeFiltrar && alvoNome
            ? `Desempenho de ${alvoNome} — score, metas e faturamento`
            : 'Seu desempenho do mês — score, metas e faturamento');

    const cabecalho = (
        <div className="flex items-center justify-between mb-6">
            <div>
                <h1 className="text-white font-display font-bold text-2xl">Painel do Publicador</h1>
                <p className="text-white/40 text-sm mt-0.5">{subtitulo}</p>
            </div>
            <div className="flex items-center gap-2">
                {podeFiltrar && (
                    <select
                        value={pubFiltro ?? ''}
                        onChange={handlePub}
                        className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer max-w-[220px]"
                    >
                        <option value="">Todos (visão geral)</option>
                        {publicadores.map(p => (
                            <option key={p.id} value={p.id}>{p.nome}</option>
                        ))}
                    </select>
                )}
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
        </div>
    );

    // Admin/Gestor/Líder sem publicador selecionado → grid de cards de toda a equipe.
    if (visaoGeral) {
        return (
            <AppLayout title="Painel do Publicador">
                {cabecalho}
                <VisaoGeralPublicadores cards={cards} onAbrir={(id) => navegar({ pub: id })} />
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Painel do Publicador">
            {cabecalho}

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

            {/* Plano de Metas: nota 0-5 + faixa de bônus + 5 indicadores */}
            {plano_metas && <PlanoMetasSection data={plano_metas} />}

            {/* Pontualidade — prazos combinados (líder edita; publicador vê a situação) */}
            <PrazosCard
                anuncios={anuncios_prazo}
                ind={plano_metas?.indicadores?.pontualidade}
                editavel={pode_gerir_metas}
            />

            {/* Absenteísmo — só o líder/gestor/admin registra */}
            {pode_gerir_metas && alvo_id && (
                <AbsenteismoCard ind={plano_metas?.indicadores?.absenteismo} alvoId={alvo_id} mesRef={mesRef} />
            )}

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
