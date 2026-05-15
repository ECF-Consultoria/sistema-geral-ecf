import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle, Building2, ExternalLink, ArrowLeft, Megaphone, Tag,
    DollarSign, ShoppingCart, MousePointer, Eye, Percent, TrendingUp,
    Clock, User as UserIcon, MessageSquare, CheckCircle2, XCircle, PlayCircle, RotateCcw,
    Image as ImageIcon, Layers,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const STATUS_LABELS = {
    pendente:  'Pendente',
    em_acao:   'Em ação',
    resolvido: 'Resolvido',
    ignorado:  'Ignorado',
};
const STATUS_BADGE = {
    pendente:  'bg-red-500/15 text-red-300 border-red-500/30',
    em_acao:   'bg-amber-500/15 text-amber-300 border-amber-500/30',
    resolvido: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    ignorado:  'bg-zinc-500/15 text-zinc-300 border-zinc-500/30',
};
const ACOES_AUDIT_LABEL = {
    marcou_em_acao:    'marcou como em ação',
    marcou_resolvido:  'resolveu',
    marcou_ignorado:   'ignorou',
    voltou_pendente:   'voltou para pendente',
    editou_observacao: 'editou observação',
};
const ACOES_AUDIT_ICON = {
    marcou_em_acao:    PlayCircle,
    marcou_resolvido:  CheckCircle2,
    marcou_ignorado:   XCircle,
    voltou_pendente:   RotateCcw,
    editou_observacao: MessageSquare,
};
const ACAO_TOMADA_LABELS = {
    pausado:        'Pausado',
    removido:       'Removido',
    reduzido_lance: 'Lance reduzido',
    reativado:      'Reativado',
    outro:          'Outro',
};
const MOTIVO_INFO = {
    gasto_sem_venda: {
        label: 'Gasto sem venda',
        explain: (s) => `Gastou ${fmtBRL(s.investimento_periodo)} sem registrar nenhuma venda no período.`,
    },
    cpc_alto: {
        label: 'CPC alto',
        explain: (s) => `CPC médio de ${fmtBRL(s.cpc_medio)} sem conversão.`,
    },
    acos_alto: {
        label: 'ACOS alto',
        explain: (s) => `ACOS de ${fmtPct(s.acos)} acima do limite configurado.`,
    },
    cliques_sem_conversao: {
        label: 'Cliques sem conversão',
        explain: (s) => `${fmtInt(s.cliques)} cliques recebidos sem nenhuma venda.`,
    },
    pct_anuncios_sugadores: {
        label: '% de adgroups sugadores',
        explain: () => 'A maioria dos adgroups desta campanha está sangrando — vale reavaliar a estratégia.',
    },
};

const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const fmtPct = (n) => n == null ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%';
// Aceita "YYYY-MM-DD" e também ISO datetime do cast 'date' do Laravel.
const fmtDate = (d) => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR') : '—';
const fmtDateTime = (dt) => dt ? new Date(dt).toLocaleString('pt-BR') : '—';

function Metric({ icon: Icon, label, value, color = 'white' }) {
    const colors = {
        white:  'text-white',
        yellow: 'text-ecf-yellow',
        red:    'text-red-400',
        green:  'text-emerald-400',
        blue:   'text-blue-400',
        purple: 'text-purple-400',
    };
    return (
        <div className="card-ecf rounded-xl p-4">
            <div className="flex items-center gap-2 mb-2">
                <Icon size={13} className="text-white/40" />
                <span className="text-white/40 text-[10px] font-semibold uppercase tracking-wide">{label}</span>
            </div>
            <p className={cn('font-display font-bold text-xl tabular-nums', colors[color])}>{value}</p>
        </div>
    );
}

export default function SugadoresShow({ sugador, url_anuncio, url_ads, can_update }) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        status:      sugador.status === 'pendente' ? 'em_acao' : sugador.status,
        acao_tomada: sugador.acao_tomada || '',
        observacao:  sugador.observacao || '',
    });

    // Info do adgroup vem do raw_data salvo na análise (sem chamada extra à Adman).
    const adgroupInfo = sugador.tipo === 'adgroup' ? (sugador.raw_data || {}) : null;

    function submit(e) {
        e.preventDefault();
        patch(route('sugadores.update-status', sugador.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    const TipoIcon = sugador.tipo === 'campanha' ? Megaphone : Tag;
    const itemTitle = sugador.tipo === 'campanha'
        ? (sugador.campaign_name || sugador.campaign_id)
        : (sugador.adgroup_name || sugador.mlb_titulo || sugador.adgroup_id || sugador.campaign_id);

    return (
        <AppLayout title="Sugador">
            {/* Voltar */}
            <Link
                href={route('sugadores.index')}
                className="inline-flex items-center gap-2 text-white/50 hover:text-white text-sm mb-4"
            >
                <ArrowLeft size={14} />
                Voltar para lista
            </Link>

            {/* Header card */}
            <div className="card-ecf rounded-xl p-5 mb-4">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-3 mb-2">
                            <span className={cn('inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border', STATUS_BADGE[sugador.status])}>
                                {STATUS_LABELS[sugador.status]}
                            </span>
                            <span className="inline-flex items-center gap-1.5 text-white/60 text-xs">
                                <TipoIcon size={13} />
                                {sugador.tipo === 'campanha' ? 'Campanha' : 'Adgroup'}
                            </span>
                        </div>

                        <h1 className="text-white font-display font-bold text-xl truncate">{itemTitle}</h1>

                        <div className="flex items-center gap-4 mt-2 text-xs text-white/50 flex-wrap">
                            <div className="flex items-center gap-1.5">
                                <Building2 size={12} />
                                <Link href={route('sugadores.index', { company_id: sugador.company?.id })} className="hover:text-white underline-offset-2 hover:underline">
                                    {sugador.company?.name || '—'}
                                </Link>
                            </div>
                            <span>· Detectado {fmtDate(sugador.reference_date)}</span>
                            <span>· Janela {fmtDate(sugador.periodo_inicio)} → {fmtDate(sugador.periodo_fim)}</span>
                            {sugador.campaign_status && (
                                <span className="px-1.5 py-0.5 rounded bg-white/[0.05] text-[10px] uppercase tracking-wider">
                                    ML: {sugador.campaign_status}
                                </span>
                            )}
                        </div>

                        <div className="flex items-center gap-3 mt-3 text-[11px] text-white/40 font-mono">
                            <span>campaign: <span className="text-white/60">{sugador.campaign_id}</span></span>
                            {sugador.adgroup_id && <span>adgroup: <span className="text-white/60">{sugador.adgroup_id}</span></span>}
                            {sugador.mlb_id && <span>MLB: <span className="text-white/60">{sugador.mlb_id}</span></span>}
                        </div>
                    </div>

                    <div className="flex flex-col gap-2 shrink-0">
                        {url_anuncio && (
                            <a
                                href={url_anuncio}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                            >
                                <ExternalLink size={13} />
                                Ver anúncio no ML
                            </a>
                        )}
                        {url_ads && (
                            <a
                                href={url_ads}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                            >
                                <ExternalLink size={13} />
                                Painel de Ads
                            </a>
                        )}
                    </div>
                </div>
            </div>

            {/* Métricas */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <Metric icon={DollarSign}   label="Investimento" value={fmtBRL(sugador.investimento_periodo)} color="red" />
                <Metric icon={ShoppingCart} label="Vendas"       value={fmtInt(sugador.vendas_periodo)}        color={sugador.vendas_periodo > 0 ? 'green' : 'white'} />
                <Metric icon={DollarSign}   label="Faturamento"  value={fmtBRL(sugador.faturamento_periodo)}   color="green" />
                <Metric icon={Percent}      label="ACOS"          value={fmtPct(sugador.acos)}                  color="yellow" />
                <Metric icon={MousePointer} label="Cliques"       value={fmtInt(sugador.cliques)} />
                <Metric icon={Eye}          label="Impressões"    value={fmtInt(sugador.impressoes)} />
                <Metric icon={DollarSign}   label="CPC médio"     value={sugador.cpc_medio == null ? '—' : fmtBRL(sugador.cpc_medio)} color="blue" />
                <Metric icon={TrendingUp}   label="ROAS"          value={sugador.roas == null ? '—' : Number(sugador.roas).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} color="purple" />
            </div>

            {/* Motivos */}
            <div className="card-ecf rounded-xl p-5 mb-4">
                <div className="flex items-center gap-2 mb-3">
                    <AlertTriangle size={14} className="text-red-400" />
                    <h2 className="text-white font-display font-semibold text-base">Por que foi flagado</h2>
                </div>
                <ul className="space-y-2">
                    {(sugador.motivos || []).map(m => {
                        const info = MOTIVO_INFO[m] || { label: m, explain: () => '' };
                        return (
                            <li key={m} className="flex gap-3">
                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-500/10 text-red-300 border border-red-500/20 shrink-0 mt-0.5 h-fit">
                                    {info.label}
                                </span>
                                <span className="text-white/60 text-sm flex-1">{info.explain(sugador)}</span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            {/* Info do adgroup (só pra tipo=adgroup) — usa raw_data salvo na análise */}
            {adgroupInfo && (
                <div className="card-ecf rounded-xl p-5 mb-4">
                    <div className="flex items-center gap-2 mb-4">
                        <Layers size={14} className="text-white/40" />
                        <h2 className="text-white font-display font-semibold text-base">Detalhes do Adgroup</h2>
                    </div>

                    <div className="flex gap-4 items-start">
                        {adgroupInfo.thumbnail ? (
                            <img
                                src={adgroupInfo.thumbnail}
                                alt={adgroupInfo.name || ''}
                                className="w-20 h-20 rounded-lg object-cover border border-white/[0.08] shrink-0"
                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                        ) : (
                            <div className="w-20 h-20 rounded-lg bg-white/[0.04] border border-white/[0.08] flex items-center justify-center shrink-0">
                                <ImageIcon size={20} className="text-white/30" />
                            </div>
                        )}

                        <div className="flex-1 min-w-0 space-y-2">
                            <div className="flex flex-wrap gap-2 items-center">
                                {adgroupInfo.adGroupType && (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-500/15 text-blue-300 border border-blue-500/30">
                                        Tipo: {adgroupInfo.adGroupType}
                                    </span>
                                )}
                                {adgroupInfo.status && (
                                    <span className={cn(
                                        'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border',
                                        adgroupInfo.status === 'ACTIVE'
                                            ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                                            : 'bg-zinc-500/15 text-zinc-300 border-zinc-500/30'
                                    )}>
                                        Status: {adgroupInfo.status}
                                    </span>
                                )}
                                {adgroupInfo.catalogListing && (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-purple-500/15 text-purple-300 border border-purple-500/30">
                                        Anúncio de catálogo
                                    </span>
                                )}
                            </div>

                            {(adgroupInfo.limitAcos != null || adgroupInfo.limitTacos != null || adgroupInfo.limitRoas != null) && (
                                <div className="flex flex-wrap gap-3 text-[11px] text-white/60">
                                    {adgroupInfo.limitAcos  != null && <span>Limite ACOS: <b className="text-white/80">{adgroupInfo.limitAcos}%</b></span>}
                                    {adgroupInfo.limitTacos != null && <span>Limite TACOS: <b className="text-white/80">{adgroupInfo.limitTacos}%</b></span>}
                                    {adgroupInfo.limitRoas  != null && <span>Limite ROAS: <b className="text-white/80">{adgroupInfo.limitRoas}</b></span>}
                                </div>
                            )}

                            <p className="text-white/30 text-[10px] leading-relaxed">
                                A API da Adman não expõe a lista de MLBs (anúncios) dentro de um adgroup —
                                {adgroupInfo.adGroupType === 'CATALOG'  && ' este é um adgroup de catálogo (1 anúncio de catalogo).'}
                                {adgroupInfo.adGroupType === 'ITEM'     && ' este tipo tem 1 anúncio único.'}
                                {adgroupInfo.adGroupType === 'FAMILY'   && ' este tipo agrupa variações (N anúncios).'}
                                {' '}Para ver e gerenciar os MLBs específicos, acesse o painel de Ads do Mercado Livre no botão acima.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {/* Form de ação */}
                {can_update && (
                    <div className="card-ecf rounded-xl p-5 lg:col-span-2">
                        <h2 className="text-white font-display font-semibold text-base mb-4">Atualizar status</h2>

                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Status</label>
                                    <select
                                        value={data.status}
                                        onChange={e => setData('status', e.target.value)}
                                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                                    >
                                        <option value="em_acao">Em ação</option>
                                        <option value="resolvido">Resolvido</option>
                                        <option value="ignorado">Ignorado</option>
                                        <option value="pendente">Voltar para pendente</option>
                                    </select>
                                    {errors.status && <p className="text-red-400 text-xs mt-1">{errors.status}</p>}
                                </div>

                                {data.status === 'resolvido' && (
                                    <div>
                                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Ação tomada</label>
                                        <select
                                            value={data.acao_tomada}
                                            onChange={e => setData('acao_tomada', e.target.value)}
                                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                                        >
                                            <option value="">— selecione —</option>
                                            {Object.entries(ACAO_TOMADA_LABELS).map(([v, l]) => (
                                                <option key={v} value={v}>{l}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                            </div>

                            <div>
                                <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Observação</label>
                                <textarea
                                    value={data.observacao}
                                    onChange={e => setData('observacao', e.target.value)}
                                    rows={3}
                                    placeholder="Notas sobre a ação tomada"
                                    className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                                />
                                {errors.observacao && <p className="text-red-400 text-xs mt-1">{errors.observacao}</p>}
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold"
                                >
                                    {processing ? 'Salvando...' : 'Salvar'}
                                </button>
                            </div>
                        </form>

                        {sugador.status === 'resolvido' && sugador.resolvido_em && (
                            <div className="mt-4 pt-4 border-t border-white/[0.06] text-xs text-white/50 flex items-center gap-2">
                                <CheckCircle2 size={12} className="text-emerald-400" />
                                Resolvido em {fmtDateTime(sugador.resolvido_em)} por <span className="text-white/70">{sugador.resolvido_por?.name || '—'}</span>
                                {sugador.acao_tomada && <span> · {ACAO_TOMADA_LABELS[sugador.acao_tomada]}</span>}
                            </div>
                        )}
                    </div>
                )}

                {/* Timeline de ações */}
                <div className={cn('card-ecf rounded-xl p-5', !can_update && 'lg:col-span-3')}>
                    <h2 className="text-white font-display font-semibold text-base mb-4 flex items-center gap-2">
                        <Clock size={14} className="text-white/40" />
                        Histórico
                    </h2>

                    {(sugador.acoes ?? []).length === 0 ? (
                        <p className="text-white/40 text-sm">Sem ações registradas ainda.</p>
                    ) : (
                        <ul className="space-y-3">
                            {sugador.acoes.map(a => {
                                const Icon = ACOES_AUDIT_ICON[a.acao] || MessageSquare;
                                return (
                                    <li key={a.id} className="flex gap-3">
                                        <div className="w-7 h-7 rounded-full bg-white/[0.05] border border-white/[0.08] flex items-center justify-center shrink-0">
                                            <Icon size={12} className="text-white/60" />
                                        </div>
                                        <div className="flex-1 min-w-0 pb-3 border-b border-white/[0.04] last:border-0">
                                            <p className="text-white/80 text-[13px]">
                                                <span className="font-semibold">{a.user?.name || 'Usuário'}</span>
                                                <span className="text-white/50"> {ACOES_AUDIT_LABEL[a.acao] || a.acao}</span>
                                            </p>
                                            <p className="text-white/30 text-[11px] mt-0.5">{fmtDateTime(a.created_at)}</p>
                                            {a.observacao && (
                                                <p className="mt-1 text-white/60 text-xs italic border-l-2 border-white/[0.08] pl-2">
                                                    {a.observacao}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
