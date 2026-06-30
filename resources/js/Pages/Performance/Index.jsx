import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { Trophy, ChevronDown, TrendingUp, CheckSquare, ChevronRight } from 'lucide-react';
import { cn, formatPercent, formatCurrency } from '@/lib/utils';

const PERIOD_OPTIONS = [
    { value: '7',   label: 'Últimos 7 dias' },
    { value: '30',  label: 'Últimos 30 dias' },
    { value: '90',  label: 'Últimos 90 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

const roleLabel    = { consultor: 'Analista', mentor: 'Estrategista' };
const pubRoleLabel = { publicador: 'Publicador', lider: 'Líder POLOS' };

const STATUS_COLOR = {
    'Acima da meta':  'text-emerald-400',
    'No alvo':        'text-ecf-yellow',
    'Abaixo da meta': 'text-red-400',
};

function NpsTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 9 ? 'text-emerald-400' : value >= 7 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-display font-extrabold text-lg', color)}>{value}</span>;
}

function GrowthTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value > 10 ? 'text-emerald-400' : value > 0 ? 'text-ecf-yellow' : 'text-red-400';
    const sign = value > 0 ? '+' : '';
    return <span className={cn('font-semibold text-[13px]', color)}>{sign}{value}%</span>;
}

function PpaTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 70 ? 'text-emerald-400' : value >= 40 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-semibold text-[13px]', color)}>{value}%</span>;
}

function PercentTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 100 ? 'text-emerald-400' : value >= 70 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-semibold text-[13px]', color)}>{value}%</span>;
}

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

export default function PerformanceIndex({ ranking = [], period = '30', setor = 'consultoria', cargo = null, mes, meses = [] }) {
    const applyFilter = (params) => {
        router.get(route('performance.index'), params, { preserveState: true });
    };

    const isPolos = setor === 'polos';

    return (
        <AppLayout title="Desempenho">
            <div className="space-y-5 max-w-[1100px]">
                {/* Header */}
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-2.5">
                        <Trophy size={18} className="text-ecf-yellow/70" />
                        <p className="text-white/50 text-sm">Ranking de desempenho</p>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Toggle Consultoria|Publicações removido — rota define o ranking (Phase 49 UAT 2026-06-30) */}

                        {/* Filtro por cargo — só no setor consultoria */}
                        {!isPolos && (
                            <div className="flex rounded-xl border border-white/[0.08] overflow-hidden">
                                {[
                                    { label: 'Geral',         value: null },
                                    { label: 'Analistas',     value: 'analista' },
                                    { label: 'Estrategistas', value: 'estrategista' },
                                ].map((opt, i, arr) => (
                                    <div key={opt.value ?? 'geral'} className="flex">
                                        <button
                                            onClick={() => applyFilter(
                                                opt.value
                                                    ? { setor: 'consultoria', period, cargo: opt.value }
                                                    : { setor: 'consultoria', period }
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
                        )}

                        {/* Period / Month selector */}
                        {!isPolos ? (
                            <SelectBox value={period} onChange={v => applyFilter({ setor: 'consultoria', period: v })}>
                                {PERIOD_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </SelectBox>
                        ) : (
                            <SelectBox value={mes} onChange={v => applyFilter({ setor: 'polos', mes: v })}>
                                {meses.map(m => <option key={m} value={m}>{formatMesLabel(m)}</option>)}
                            </SelectBox>
                        )}
                    </div>
                </div>

                {/* Legend — quick 260623 ranking por score */}
                {!isPolos && (
                    <div className="flex flex-wrap gap-4 text-[11px] text-white/30">
                        <span className="flex items-center gap-1.5"><TrendingUp size={12} /> Score: 30% cresc. ajustado + 20% empresas crescendo + 20% meta + 15% recuperação + 5% qualidade · pesos redistribuem quando faltam dados</span>
                        <span>Click na linha → carteira individual com detalhe da nota</span>
                    </div>
                )}

                {ranking.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-12 text-center">
                        <Trophy size={32} className="mx-auto mb-3 text-white/20" />
                        <p className="text-white/40 text-sm">Nenhum dado disponível para o período selecionado</p>
                    </div>
                ) : isPolos ? (
                    <RankingPolos ranking={ranking} />
                ) : (
                    <RankingConsultoria ranking={ranking} />
                )}
            </div>
        </AppLayout>
    );
}

// Quick 260623 — ranking justo por SCORE (não por faturamento bruto).
// Colunas conforme metodologia-desempenho-carteira.md. Click leva pra
// carteira individual onde o profissional ve o detalhe da nota.

const CLASSIF_CFG = {
    excelente: { label: 'Excelente', cls: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' },
    bom:       { label: 'Bom',       cls: 'bg-sky-500/15 text-sky-300 border-sky-500/30' },
    atencao:   { label: 'Atenção',   cls: 'bg-amber-500/15 text-amber-300 border-amber-500/30' },
    critico:   { label: 'Crítico',   cls: 'bg-red-500/15 text-red-300 border-red-500/30' },
};

function ScorePill({ score, classif }) {
    const cfg = CLASSIF_CFG[classif] ?? CLASSIF_CFG.atencao;
    return (
        <div className="flex items-center gap-1.5">
            <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', cfg.cls)}>
                {cfg.label}
            </span>
            <span className="text-white tabular-nums font-bold text-[13px]">{score}</span>
        </div>
    );
}

function PctTone({ value, good = 60, okay = 40, suffix = '%' }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const tone = value >= good ? 'text-emerald-300' : value >= okay ? 'text-amber-300' : 'text-red-300';
    return <span className={cn('font-semibold tabular-nums text-[12px]', tone)}>{value.toFixed(0)}{suffix}</span>;
}

function GrowthTone({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const tone = value >= 5 ? 'text-emerald-300' : value <= -5 ? 'text-red-300' : 'text-white/60';
    const sign = value > 0 ? '+' : '';
    return <span className={cn('font-semibold tabular-nums text-[12px]', tone)}>{sign}{value.toFixed(1)}%</span>;
}

function Tendencia({ value }) {
    const cfg = {
        subindo:  { label: '↑ subindo',  cls: 'text-emerald-300' },
        estavel:  { label: '— estável',  cls: 'text-white/60' },
        descendo: { label: '↓ descendo', cls: 'text-red-300' },
        sem_dado: { label: '—',          cls: 'text-white/20' },
    }[value] ?? { label: '—', cls: 'text-white/20' };
    return <span className={cn('text-[11px] font-semibold', cfg.cls)}>{cfg.label}</span>;
}

function RankingConsultoria({ ranking }) {
    return (
        <div className="card-ecf rounded-2xl overflow-hidden">
            <div className="grid grid-cols-[2.5rem_1fr_5rem_8rem_5rem_5rem_5rem_4.5rem_4.5rem_2rem] gap-2 px-5 py-3 border-b border-white/[0.06] text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                <span>#</span>
                <span>Nome</span>
                <span className="text-right cursor-help" title="Empresas elegíveis (revenue > 0 no período atual ou anterior) / total de empresas ativas na carteira.">Empresas ⓘ</span>
                <span className="cursor-help" title="Score 0-100 ponderado: 30% crescimento ajustado + 20% empresas crescendo + 20% atingimento de meta + 15% recuperação + 5% qualidade (NPS+reuniões). Cobertura Ads descontinuada — peso redistribuído. Categorias sem dado também redistribuem automaticamente.">Score ⓘ</span>
                <span className="text-right cursor-help" title="Crescimento ajustado da carteira: revenue dos últimos 30d vs revenue_prev_period reportado pela Adman pra mesma janela.">Cresc. ⓘ</span>
                <span className="text-right cursor-help" title="% de empresas elegíveis que tiveram revenue atual > revenue do período anterior.">Crescendo ⓘ</span>
                <span className="text-right cursor-help" title="Atingimento da meta: usa PortfolioGoal de revenue ativo, ou soma das metas individuais (Goal de revenue por empresa) ativas se não houver meta de carteira.">Meta ⓘ</span>
                <span className="text-right cursor-help" title="NPS médio das respostas dos últimos 30d (escala 1-5).">NPS ⓘ</span>
                <span className="cursor-help" title="Tendência baseada no crescimento ajustado: ≥+5% subindo, ≤-5% descendo, no meio estável.">Tend. ⓘ</span>
                <span />
            </div>

            <div className="divide-y divide-white/[0.04]">
                {ranking.map((u, idx) => (
                    <div
                        key={u.id}
                        onClick={() => router.visit(route('portfolio.show', u.id))}
                        className={cn(
                            'grid grid-cols-[2.5rem_1fr_5rem_8rem_5rem_5rem_5rem_4.5rem_4.5rem_2rem] gap-2 px-5 py-3 items-center transition-colors hover:bg-white/[0.04] cursor-pointer',
                            idx === 0 && u.tem_base_comparativa && 'bg-ecf-yellow/[0.03]',
                            !u.tem_base_comparativa && 'opacity-60'
                        )}
                        title={!u.tem_base_comparativa ? 'Carteira < 5 empresas elegíveis — score sem base comparativa robusta' : undefined}
                    >
                        <div className="flex items-center justify-center">
                            {idx < 3 && u.tem_base_comparativa
                                ? <MedalBadge idx={idx} />
                                : <span className="text-white/40 font-semibold text-[12px] tabular-nums">{u.posicao}</span>}
                        </div>

                        <div>
                            <p className="text-white font-semibold text-[13px]">{u.name}</p>
                            <p className="text-white/30 text-[11px]">
                                {u.cargo_label}
                                {!u.tem_base_comparativa && <span className="text-amber-300/70"> · base insuficiente</span>}
                            </p>
                        </div>

                        <div className="text-right text-white/70 tabular-nums text-[12px]">
                            {u.empresas_eligiveis}/{u.empresas_carteira}
                        </div>

                        <ScorePill score={u.score} classif={u.classificacao} />

                        <div className="text-right"><GrowthTone value={u.crescimento_ajustado_pct} /></div>
                        <div className="text-right"><PctTone value={u.empresas_em_crescimento_pct} good={60} okay={40} /></div>
                        <div className="text-right"><PctTone value={u.atingimento_meta_pct} good={80} okay={50} /></div>

                        <div className="text-right">
                            {u.avg_nps !== null && u.avg_nps !== undefined
                                ? <span className="text-white/85 font-semibold tabular-nums text-[12px]">{u.avg_nps.toFixed(1)}</span>
                                : <span className="text-white/20 font-bold">—</span>}
                        </div>

                        <div><Tendencia value={u.tendencia} /></div>

                        <div className="flex items-center justify-end">
                            <ChevronRight size={14} className="text-white/20" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ScoreTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 75 ? 'text-emerald-400' : value >= 50 ? 'text-ecf-yellow' : value >= 30 ? 'text-orange-400' : 'text-red-400';
    const barColor = value >= 75 ? '#22c55e' : value >= 50 ? '#ffe600' : value >= 30 ? '#f97316' : '#ef4444';
    return (
        <div className="flex flex-col items-end gap-1">
            <span className={cn('font-extrabold text-[15px] leading-none', color)}>{value}</span>
            <div className="w-14 h-1 bg-white/10 rounded-full overflow-hidden">
                <div className="h-full rounded-full transition-all" style={{ width: `${Math.min(value, 100)}%`, background: barColor }} />
            </div>
        </div>
    );
}

function RankingPolos({ ranking }) {
    return (
        <div className="card-ecf rounded-2xl overflow-hidden">
            {/* Header */}
            <div className="grid grid-cols-[2.5rem_1fr_4rem_4rem_4.5rem_4rem_5rem_5.5rem_7rem] gap-2 px-5 py-3 border-b border-white/[0.06] text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                <span>#</span>
                <span>Nome</span>
                <span className="text-right">Meta</span>
                <span className="text-right">Feito</span>
                <span className="text-right">% Meta</span>
                <span className="text-right">Vendas</span>
                <span className="text-right">Conversão</span>
                <span className="text-right flex items-center justify-end gap-1">
                    Score
                    <span className="normal-case font-normal text-white/20 text-[10px]">/100</span>
                </span>
                <span className="text-right">Status</span>
            </div>

            <div className="divide-y divide-white/[0.04]">
                {ranking.map((u, idx) => (
                    <div
                        key={u.id}
                        className={cn(
                            'grid grid-cols-[2.5rem_1fr_4rem_4rem_4.5rem_4rem_5rem_5.5rem_7rem] gap-2 px-5 py-4 items-center',
                            idx === 0 && 'bg-ecf-yellow/[0.03]'
                        )}
                    >
                        <div className="flex items-center justify-center">
                            <MedalBadge idx={idx} />
                        </div>

                        <div>
                            <p className="text-white font-semibold text-[13px]">{u.name}</p>
                            <p className="text-white/30 text-[11px]">{pubRoleLabel[u.pub_role]}</p>
                        </div>

                        <div className="text-right">
                            <span className="text-white/50 text-[13px]">{u.meta}</span>
                        </div>

                        <div className="text-right">
                            <span className="text-white font-bold text-[15px]">{u.feito}</span>
                        </div>

                        <div className="text-right">
                            <PercentTag value={u.percentual} />
                        </div>

                        <div className="text-right">
                            <span className="text-blue-400 font-semibold text-[13px]">{u.vendas}</span>
                        </div>

                        <div className="text-right">
                            {u.conversao > 0
                                ? <span className="text-white/70 font-semibold text-[13px]">{u.conversao}%</span>
                                : <span className="text-white/20 font-bold">—</span>}
                        </div>

                        <div className="flex justify-end">
                            <ScoreTag value={u.score_final} />
                        </div>

                        <div className="text-right">
                            <span className={cn('font-semibold text-[11px]', STATUS_COLOR[u.status] ?? 'text-white/40')}>
                                {u.status}
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
