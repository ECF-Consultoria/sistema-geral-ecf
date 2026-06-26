import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { format } from 'date-fns';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Check,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    Loader2,
    RefreshCw,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

// ─── Labels (espelha o glossario do Index.jsx) ──────────────────────────────

const STATUS_LABELS = {
    adman_only:           'So Adman',
    ml_shadow:            'Comparando em paralelo',
    ml_primary_candidate: 'Pronta pra migrar',
    ml_primary:           'Migrada pro ML',
};

const STATUS_BADGE = {
    adman_only:           'text-white/70 bg-white/[0.04] border-white/[0.08]',
    ml_shadow:            'text-blue-300 bg-blue-400/10 border-blue-400/20',
    ml_primary_candidate: 'text-amber-300 bg-amber-400/10 border-amber-400/20',
    ml_primary:           'text-emerald-300 bg-emerald-400/10 border-emerald-400/20',
};

const TOKEN_LABELS = {
    active:        'Conectado',
    error_refresh: 'Reautorizar',
    missing:       'Sem conexao',
    expired:       'Conectado',
};

// Motivos mais comuns que o backend pode retornar. Fallback: print do nome cru.
const MOTIVO_LABELS = {
    sem_vendas:            'Sem vendas no periodo',
    sem_cliques:           'Sem cliques no periodo',
    impressoes_baixas:     'Impressoes muito baixas',
    custo_alto:            'Custo alto sem retorno',
    acos_alto:             'ACOS acima do limite',
    ctr_baixo:             'CTR abaixo do esperado',
    saldo_baixo:           'Saldo de ADS baixo',
    sem_atividade:         'Sem atividade recente',
    pausado_alto_custo:    'Pausado com custo acumulado',
    metrics_zeradas:       'Todas as metricas zeradas',
};

const fmtTs   = (iso) => (iso ? format(new Date(iso), 'dd/MM HH:mm') : '—');
const fmtDate = (iso) => (iso ? format(new Date(iso), 'dd/MM/yyyy') : '—');
const fmtBRL  = (n) => {
    if (n === null || n === undefined || isNaN(Number(n))) return '—';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(n));
};
const fmtPct  = (n) => (typeof n === 'number' ? `${n.toFixed(1)}%` : '—');
const fmtInt  = (n) => {
    if (n === null || n === undefined || isNaN(Number(n))) return '—';
    return new Intl.NumberFormat('pt-BR').format(Number(n));
};

function MotivoBadge({ motivo }) {
    const label = MOTIVO_LABELS[motivo] ?? motivo;
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border text-amber-200 bg-amber-400/10 border-amber-400/20">
            {label}
        </span>
    );
}

function DevCard({ icon: Icon, title, subtitle, children, action }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
            <div className="flex items-start gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <Icon size={18} className="text-ecf-yellow" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-white font-semibold text-[15px] leading-tight">{title}</h3>
                    {subtitle && <p className="text-white/40 text-[12px] mt-0.5">{subtitle}</p>}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </div>
            {children}
        </div>
    );
}

function HeaderEmpresa({ company }) {
    const status = company.status;
    const token  = company.token_state;
    return (
        <div className="flex flex-wrap items-center gap-4">
            <div>
                <div className="text-white text-xl font-display font-bold">{company.name}</div>
                <div className="text-white/40 text-[12px]">#{company.id}</div>
            </div>
            <span className={cn(
                'inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border',
                STATUS_BADGE[status] ?? 'text-white/40 bg-white/[0.04] border-white/[0.08]',
            )}>
                {STATUS_LABELS[status] ?? status}
            </span>
            <span className={cn(
                'inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border',
                token === 'active' || token === 'expired'
                    ? 'text-emerald-300 bg-emerald-400/10 border-emerald-400/20'
                    : token === 'error_refresh'
                        ? 'text-red-300 bg-red-400/10 border-red-400/20'
                        : 'text-white/40 bg-white/[0.04] border-white/[0.08]',
            )}>
                {TOKEN_LABELS[token] ?? token}
            </span>
            {company.advertiser === 'ok' && (
                <span className="inline-flex items-center gap-1 text-emerald-300 text-[12px]">
                    <Check size={14} /> Anunciante ok
                </span>
            )}
            {typeof company.shadow_paridade_7d === 'number' && (
                <span className="text-[12px] text-white/60">
                    Concordancia 7d: <span className={
                        company.shadow_paridade_7d >= 95 ? 'text-emerald-300 font-mono' :
                        company.shadow_paridade_7d >= 80 ? 'text-amber-300 font-mono' :
                        'text-red-300 font-mono'
                    }>{fmtPct(company.shadow_paridade_7d)}</span>
                </span>
            )}
        </div>
    );
}

// ─── MLBs do adgroup (chamada XHR sob demanda) ──────────────────────────────

function MlbsDoAdgroup({ companyId, adgroupId }) {
    const [estado, setEstado] = useState({ loading: true, error: null, data: null });

    if (estado.data === null && estado.loading) {
        // Dispara a fetch apenas uma vez por mount
        axios.get(route('dev.sugadores_ml_onboarding.adgroup_mlbs', { company: companyId, adgroupId }))
            .then((resp) => setEstado({ loading: false, error: null, data: resp.data }))
            .catch((err) => setEstado({ loading: false, error: err?.response?.data?.reason ?? 'Falha ao carregar MLBs.', data: null }));
    }

    if (estado.loading) {
        return (
            <div className="flex items-center gap-2 text-white/50 text-[12px] py-3">
                <Loader2 size={14} className="animate-spin" /> Carregando MLBs do adgroup...
            </div>
        );
    }

    if (estado.error) {
        return (
            <div className="flex items-start gap-2 rounded-lg bg-red-400/[0.06] border border-red-400/20 px-3 py-2.5 text-[12px] text-red-200">
                <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                <div>{estado.error}</div>
            </div>
        );
    }

    const { mlbs = [], total = 0, last_synced_at, is_fresh, empty_state } = estado.data ?? {};

    if (empty_state === 'never_synced') {
        return (
            <div className="text-white/50 text-[12px] py-3">
                Esta empresa nunca teve sync Adman de MLBs por adgroup. Rode o sync Adman e tente de novo.
            </div>
        );
    }

    if (empty_state === 'no_mlbs') {
        return (
            <div className="text-white/50 text-[12px] py-3">
                Adgroup ainda sem MLBs sincronizados nos ultimos 30 dias.
                {last_synced_at && <> Ultimo sync: {fmtTs(last_synced_at)}.</>}
            </div>
        );
    }

    return (
        <div className="mt-2">
            <div className="flex items-center gap-3 mb-2 text-[11px] text-white/50">
                <span>{total} MLB{total === 1 ? '' : 's'} sincronizados</span>
                <span>Ultimo sync: {fmtTs(last_synced_at)}</span>
                {!is_fresh && (
                    <span className="text-amber-300 inline-flex items-center gap-1">
                        <AlertTriangle size={12} /> Sync antigo (&gt; 30h)
                    </span>
                )}
            </div>
            <div className="overflow-x-auto rounded-md border border-white/[0.06]">
                <table className="min-w-full text-[12px]">
                    <thead className="text-white/40 text-[10px] uppercase tracking-wider bg-black/30">
                        <tr>
                            <th className="text-left px-3 py-2">MLB</th>
                            <th className="text-left px-3 py-2">Anuncio</th>
                            <th className="text-right px-3 py-2">Custo</th>
                            <th className="text-right px-3 py-2">Receita</th>
                            <th className="text-right px-3 py-2">Vendas</th>
                            <th className="text-right px-3 py-2">Cliques</th>
                            <th className="text-right px-3 py-2">Impressoes</th>
                            <th className="text-right px-3 py-2">ACOS</th>
                            <th className="text-right px-3 py-2">CTR</th>
                            <th className="text-center px-3 py-2">Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        {mlbs.map((m, i) => {
                            const mlbId = m.mlb_id ?? m.mlbId ?? '—';
                            const titulo = m.title ?? m.titulo ?? m.product_title ?? '—';
                            return (
                                <tr key={`${mlbId}-${i}`} className="border-t border-white/[0.04] hover:bg-white/[0.02]">
                                    <td className="px-3 py-2 font-mono text-white/80">{mlbId}</td>
                                    <td className="px-3 py-2 text-white/70 max-w-xs truncate" title={titulo}>{titulo}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{fmtBRL(m.cost ?? m.custo)}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{fmtBRL(m.revenue ?? m.receita)}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{fmtInt(m.units ?? m.vendas ?? m.unidades)}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{fmtInt(m.clicks ?? m.cliques)}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{fmtInt(m.impressions ?? m.impressoes)}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{m.acos !== null && m.acos !== undefined ? fmtPct(Number(m.acos)) : '—'}</td>
                                    <td className="px-3 py-2 text-right font-mono text-white/70">{m.ctr !== null && m.ctr !== undefined ? fmtPct(Number(m.ctr)) : '—'}</td>
                                    <td className="px-3 py-2 text-center">
                                        {mlbId && mlbId !== '—' && (
                                            <a
                                                href={`https://produto.mercadolivre.com.br/${mlbId.replace(/^MLB/, 'MLB-')}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center text-white/40 hover:text-ecf-yellow"
                                                title="Abrir anuncio no Mercado Livre"
                                            >
                                                <ExternalLink size={12} />
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ─── Linha de adgroup com expand ────────────────────────────────────────────

function LinhaAdgroup({ row, companyId, expandida, onToggle }) {
    const metrics  = row.metrics_json ?? {};
    const motivos  = row.motivos ?? [];
    const isMlb    = row.tipo === 'mlb';

    return (
        <>
            <tr
                className="border-b border-white/[0.04] hover:bg-white/[0.02] cursor-pointer transition-colors"
                onClick={onToggle}
            >
                <td className="px-3 py-3 text-white/60">
                    {expandida ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                </td>
                <td className="px-3 py-3">
                    <span className={cn(
                        'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border uppercase tracking-wide',
                        row.tipo === 'adgroup'
                            ? 'text-blue-300 bg-blue-400/10 border-blue-400/20'
                            : 'text-purple-300 bg-purple-400/10 border-purple-400/20',
                    )}>
                        {row.tipo}
                    </span>
                </td>
                <td className="px-3 py-3 font-mono text-[12px] text-white/80">{row.campaign_id || '—'}</td>
                <td className="px-3 py-3 font-mono text-[12px] text-white/80">{row.adgroup_id || '—'}</td>
                <td className="px-3 py-3">
                    <div className="flex flex-wrap gap-1">
                        {motivos.length === 0 && <span className="text-white/40 text-[11px]">—</span>}
                        {motivos.slice(0, 3).map((m, i) => (
                            <MotivoBadge key={i} motivo={m} />
                        ))}
                        {motivos.length > 3 && (
                            <span className="text-white/40 text-[11px] self-center">+{motivos.length - 3}</span>
                        )}
                    </div>
                </td>
                <td className="px-3 py-3 text-right font-mono text-white/70 text-[12px]">{fmtBRL(metrics.cost ?? metrics.custo)}</td>
                <td className="px-3 py-3 text-right font-mono text-white/70 text-[12px]">{fmtBRL(metrics.revenue ?? metrics.receita)}</td>
                <td className="px-3 py-3 text-right font-mono text-white/70 text-[12px]">
                    {typeof metrics.acos === 'number' || (typeof metrics.acos === 'string' && metrics.acos !== '')
                        ? fmtPct(Number(metrics.acos))
                        : '—'}
                </td>
            </tr>
            {expandida && (
                <tr className="bg-black/20 border-b border-white/[0.06]">
                    <td colSpan={8} className="px-3 py-4">
                        <div className="space-y-4">
                            {/* Motivos completos */}
                            <div>
                                <div className="text-white/40 text-[11px] uppercase tracking-wider mb-2">
                                    Por que foi flagrado ({motivos.length} motivo{motivos.length === 1 ? '' : 's'})
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {motivos.length === 0
                                        ? <span className="text-white/50 text-[12px]">Nenhum motivo registrado.</span>
                                        : motivos.map((m, i) => <MotivoBadge key={i} motivo={m} />)
                                    }
                                </div>
                            </div>

                            {/* Metricas completas */}
                            <div>
                                <div className="text-white/40 text-[11px] uppercase tracking-wider mb-2">Metricas detectadas</div>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-2 text-[12px]">
                                    {Object.entries(metrics).filter(([, v]) => v !== null && v !== '').map(([k, v]) => (
                                        <div key={k} className="rounded border border-white/[0.06] bg-black/20 px-2.5 py-1.5">
                                            <div className="text-white/40 text-[10px] uppercase tracking-wider">{k}</div>
                                            <div className="text-white/80 font-mono">{
                                                typeof v === 'number'
                                                    ? (k.includes('cost') || k.includes('custo') || k.includes('revenue') || k.includes('receita')
                                                        ? fmtBRL(v)
                                                        : k.includes('acos') || k.includes('ctr') || k.includes('pct')
                                                            ? fmtPct(v)
                                                            : fmtInt(v))
                                                    : String(v)
                                            }</div>
                                        </div>
                                    ))}
                                    {Object.keys(metrics).length === 0 && (
                                        <span className="text-white/50 text-[12px] col-span-4">Nenhuma metrica registrada.</span>
                                    )}
                                </div>
                            </div>

                            {/* MLBs do adgroup (so quando tipo=adgroup e tem adgroup_id) */}
                            {row.tipo === 'adgroup' && row.adgroup_id && (
                                <div>
                                    <div className="text-white/40 text-[11px] uppercase tracking-wider mb-2">
                                        Anuncios (MLBs) dentro deste adgroup
                                    </div>
                                    <MlbsDoAdgroup companyId={companyId} adgroupId={row.adgroup_id} />
                                </div>
                            )}

                            {row.tipo === 'campanha' && (
                                <div className="text-white/50 text-[12px] italic">
                                    Itens do tipo "campanha" sao agregados de toda a campanha — drilldown de MLBs disponivel apenas em itens "adgroup".
                                </div>
                            )}
                            {isMlb && row.mlb_id && (
                                <div className="text-white/60 text-[12px]">
                                    Anuncio: <span className="font-mono">{row.mlb_id}</span>
                                </div>
                            )}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

// ─── Pagina principal ───────────────────────────────────────────────────────

export default function SugadoresMlOnboardingShow({ company, run, adgroups = [], empty_state }) {
    const [expandido, setExpandido] = useState({}); // { [itemId]: true }

    function toggle(id) {
        setExpandido((prev) => ({ ...prev, [id]: !prev[id] }));
    }

    function recarregar() {
        router.reload({ only: ['adgroups', 'run', 'empty_state'] });
    }

    return (
        <AppLayout title={`Onboarding ML — ${company.name}`}>
            <div className="max-w-7xl mx-auto space-y-6">
                {/* Header / breadcrumb */}
                <div className="flex items-center gap-3">
                    <Link
                        href={route('dev.sugadores_ml_onboarding.index')}
                        className="inline-flex items-center gap-1.5 text-white/60 hover:text-ecf-yellow text-[13px] transition-colors"
                    >
                        <ArrowLeft size={14} /> Voltar para a lista
                    </Link>
                </div>

                {/* Card empresa */}
                <DevCard
                    icon={Activity}
                    title="Empresa"
                    subtitle="Identificacao e estado atual na migracao Adman → Mercado Livre."
                >
                    <HeaderEmpresa company={company} />
                </DevCard>

                {/* Card da run */}
                <DevCard
                    icon={Zap}
                    title="Ultima comparacao ML"
                    subtitle={run
                        ? `Run #${run.id} • periodo ${fmtDate(run.periodo_inicio)} ate ${fmtDate(run.periodo_fim)} • concluida ${fmtTs(run.finished_at)}.`
                        : 'Esta empresa ainda nao tem nenhuma comparacao ML completa.'}
                    action={
                        <button
                            type="button"
                            onClick={recarregar}
                            className="inline-flex items-center gap-1.5 text-[12px] text-white/60 hover:text-white px-2 py-1 rounded border border-white/[0.08] hover:bg-white/[0.05]"
                            title="Recarrega a pagina (busca a run mais recente)"
                        >
                            <RefreshCw size={12} /> Atualizar
                        </button>
                    }
                >
                    {run && (
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-[12px]">
                            <div className="rounded border border-white/[0.06] bg-black/20 px-3 py-2">
                                <div className="text-white/40 text-[10px] uppercase tracking-wider">Adgroups detectados</div>
                                <div className="text-white text-lg font-mono">{run.summary?.adgroups ?? 0}</div>
                            </div>
                            <div className="rounded border border-white/[0.06] bg-black/20 px-3 py-2">
                                <div className="text-white/40 text-[10px] uppercase tracking-wider">Campanhas detectadas</div>
                                <div className="text-white text-lg font-mono">{run.summary?.campanhas ?? 0}</div>
                            </div>
                            <div className="rounded border border-white/[0.06] bg-black/20 px-3 py-2">
                                <div className="text-white/40 text-[10px] uppercase tracking-wider">Itens gravados</div>
                                <div className="text-white text-lg font-mono">{run.summary?.items ?? 0}</div>
                            </div>
                            <div className="rounded border border-white/[0.06] bg-black/20 px-3 py-2">
                                <div className="text-white/40 text-[10px] uppercase tracking-wider">Chamadas API ML</div>
                                <div className="text-white text-lg font-mono">{run.summary?.ml_metrics?.total_calls ?? '—'}</div>
                            </div>
                        </div>
                    )}
                </DevCard>

                {/* Adgroups detectados */}
                <DevCard
                    icon={Activity}
                    title={`Adgroups e campanhas detectados (${adgroups.length})`}
                    subtitle="Clique em uma linha pra abrir os motivos, metricas e os MLBs dentro do adgroup."
                >
                    {empty_state === 'no_runs' && (
                        <div className="text-white/60 text-[13px] py-3">
                            Esta empresa ainda nao tem nenhuma comparacao ML completa.
                            Clique em <b>"Rodar comparacao"</b> na lista principal pra disparar uma agora,
                            ou ligue a comparacao paralela diaria.
                        </div>
                    )}
                    {empty_state === 'empty_run' && (
                        <div className="text-white/60 text-[13px] py-3">
                            A ultima comparacao ML rodou normalmente mas nao detectou nenhum
                            adgroup/campanha como sugador. Boa noticia: nada esta com problema
                            visivel no ML — ou ainda nao ha dados suficientes pra o algoritmo flagrar.
                        </div>
                    )}
                    {!empty_state && adgroups.length > 0 && (
                        <div className="overflow-x-auto -mx-2">
                            <table className="min-w-full text-[13px]">
                                <thead className="text-white/40 text-[11px] uppercase tracking-wider border-b border-white/[0.06]">
                                    <tr>
                                        <th className="text-left px-3 py-2 w-8"></th>
                                        <th className="text-left px-3 py-2">Tipo</th>
                                        <th className="text-left px-3 py-2">Campanha</th>
                                        <th className="text-left px-3 py-2">Adgroup</th>
                                        <th className="text-left px-3 py-2">Por que flagrado</th>
                                        <th className="text-right px-3 py-2">Custo</th>
                                        <th className="text-right px-3 py-2">Receita</th>
                                        <th className="text-right px-3 py-2">ACOS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {adgroups.map((row) => (
                                        <LinhaAdgroup
                                            key={row.id}
                                            row={row}
                                            companyId={company.id}
                                            expandida={!!expandido[row.id]}
                                            onToggle={() => toggle(row.id)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DevCard>
            </div>
        </AppLayout>
    );
}
