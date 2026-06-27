import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    AlertTriangle, Building2, ExternalLink, ArrowLeft, Megaphone, Tag,
    DollarSign, ShoppingCart, MousePointer, Eye, Percent, TrendingUp,
    Clock, MessageSquare, CheckCircle2, XCircle, PlayCircle, RotateCcw,
    Image as ImageIcon, Layers, Package, Loader2, RefreshCw,
    Copy, Check, ChevronDown, ArrowRightLeft,
} from 'lucide-react';
import MoveToSgiModal from '@/Components/MoveToSgiModal';
import { cn } from '@/lib/utils';

const STATUS_LABELS = {
    pendente:       'Pendente',
    em_acao:        'Em ação',
    resolvido:      'Resolvido',
    ignorado:       'Ignorado',
    movido:         'Movido p/ SGI',
    auto_resolvido: 'Auto-resolvido',
};
const STATUS_BADGE = {
    pendente:       'bg-red-500/15 text-red-300 border-red-500/30',
    em_acao:        'bg-amber-500/15 text-amber-300 border-amber-500/30',
    resolvido:      'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    ignorado:       'bg-zinc-500/15 text-zinc-300 border-zinc-500/30',
    movido:         'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30',
    // Verde claro distinto do `resolvido` cheio — sinaliza que o sistema resolveu sozinho.
    auto_resolvido: 'bg-emerald-500/10 text-emerald-200/70 border-emerald-500/20',
};

// Helper de copy com fallback pra intranet HTTP (navigator.clipboard exige secure context).
// Mantido no escopo do módulo pra evitar recriação a cada render.
const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        // Fallback necessário para intranet sem HTTPS — `navigator.clipboard` exige secure context.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
};
const ACOES_AUDIT_LABEL = {
    marcou_em_acao:    'marcou como em ação',
    marcou_resolvido:  'resolveu',
    marcou_ignorado:   'ignorou',
    voltou_pendente:   'voltou para pendente',
    editou_observacao: 'editou observação',
    moveu:             'moveu para campanha SGI',
};
const ACOES_AUDIT_ICON = {
    marcou_em_acao:    PlayCircle,
    marcou_resolvido:  CheckCircle2,
    marcou_ignorado:   XCircle,
    voltou_pendente:   RotateCcw,
    editou_observacao: MessageSquare,
    moveu:             ArrowRightLeft,
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

export default function SugadoresShow({ sugador, url_anuncio, url_ads, can_update, mlbs = [] }) {
    const [showMoveModal, setShowMoveModal] = useState(false);

    // Quick task 260626-qgf — `mlbs` vem do controller via AdgroupMlbMapRepository
    // (todos os MLBs do adgroup do sugador via provider ML). Propagado para o
    // componente MlbsDoAdgroup abaixo como fonte canonica de IDs — Adman MCP
    // enriquece com metricas quando disponivel, mas a lista vive no ML.
    // Decisao operador 2026-06-26: MLBs aparecem APENAS na secao "MLBs neste
    // adgroup"; nenhum bloco MLB no header (evita duplicacao visual).

    const { data, setData, patch, processing, errors, reset } = useForm({
        status:      sugador.status === 'pendente' ? 'em_acao' : sugador.status,
        acao_tomada: sugador.acao_tomada || '',
        observacao:  sugador.observacao || '',
    });

    // Alimenta o chip "Continuar com X" do Index quando o user entra direto via /sugadores/{id}
    // (link compartilhado, bookmark, navegação direta). SSR-safe via useEffect.
    useEffect(() => {
        const companyId = sugador?.company?.id;
        if (!companyId) return;
        try {
            localStorage.setItem('sugadores:last_company_id', String(companyId));
        } catch {
            // localStorage indisponível — sem efeito colateral.
        }
    }, [sugador?.company?.id]);

    // Info do adgroup: prioriza colunas tipadas (preenchidas na análise), com fallback
    // pro raw_data para registros antigos que precederam a migration de metadados.
    const adgroupInfo = sugador.tipo === 'adgroup' ? (() => {
        const raw = sugador.raw_data || {};
        return {
            thumbnail:       sugador.thumbnail       ?? raw.thumbnail,
            adGroupType:     sugador.adgroup_type    ?? raw.adGroupType,
            catalogListing:  sugador.catalog_listing ?? raw.catalogListing,
            status:          raw.status,
            limitAcos:       raw.limitAcos,
            limitTacos:      raw.limitTacos,
            limitRoas:       raw.limitRoas,
            name:            sugador.adgroup_name    ?? raw.name,
        };
    })() : null;

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
            <button
                onClick={() => window.history.back()}
                className="inline-flex items-center gap-2 text-white/50 hover:text-white text-sm mb-4"
            >
                <ArrowLeft size={14} />
                Voltar para lista
            </button>

            {/* Header card */}
            <div className="card-ecf rounded-xl p-5 mb-4">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-3 mb-2">
                            <span
                                className={cn('inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border', STATUS_BADGE[sugador.status])}
                                title={sugador.status === 'auto_resolvido' ? 'Resolvido automaticamente pelo sistema' : undefined}
                            >
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

                        {/* MLB em destaque — o time operacional não conseguia distinguir o MLB
                            dos IDs técnicos quando ficavam todos na mesma linha. Agora MLB
                            ganha badge + copiar + link direto; campaign/adgroup escondidos
                            num <details>. */}
                        {/* Quick 260626-qgf — MlbHighlight removido do header
                            (decisao operador 2026-06-26): MLBs vivem na secao
                            "MLBs neste adgroup" abaixo, sem duplicacao no topo.
                            Para tipo=campanha o mlb_id ja' era null, sem mudanca. */}
                        <div className="mt-3 space-y-2">
                            {sugador.tipo === 'adgroup' && (
                                <p className="text-[11px] text-white/40">
                                    Veja todos os MLBs deste adgroup na seção abaixo.
                                </p>
                            )}
                            <details className="group">
                                <summary className="inline-flex items-center gap-1 cursor-pointer text-[10px] text-white/30 hover:text-white/50 font-mono uppercase tracking-wider list-none">
                                    <ChevronDown size={10} className="transition-transform group-open:rotate-180" />
                                    IDs técnicos
                                </summary>
                                <div className="flex items-center gap-3 mt-1.5 text-[11px] text-white/40 font-mono pl-3">
                                    <span>campaign: <span className="text-white/60">{sugador.campaign_id}</span></span>
                                    {sugador.adgroup_id && <span>adgroup: <span className="text-white/60">{sugador.adgroup_id}</span></span>}
                                </div>
                            </details>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2 shrink-0">
                        {/* Ação primária pra adgroup-sugador: mover pra SGI.
                            Escondida para tipo=campanha (não há adgroup pra mover) e
                            pra status que já fecharam o ciclo. */}
                        {can_update && sugador.tipo === 'adgroup' && sugador.status !== 'movido' && sugador.status !== 'resolvido' && (
                            <button
                                type="button"
                                onClick={() => setShowMoveModal(true)}
                                className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 text-[13px] font-bold"
                            >
                                <ArrowRightLeft size={13} />
                                Mover para SGI
                            </button>
                        )}
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

                            {(sugador.organic_amount != null || sugador.organic_units != null) && (
                                <div className="flex flex-wrap gap-3 text-[11px] text-white/60 pt-1">
                                    <span className="text-white/40 uppercase tracking-wider font-semibold text-[10px]">Contexto orgânico:</span>
                                    {sugador.organic_units != null && (
                                        <span>{fmtInt(sugador.organic_units)} venda{Number(sugador.organic_units) !== 1 ? 's' : ''} orgânica{Number(sugador.organic_units) !== 1 ? 's' : ''}</span>
                                    )}
                                    {sugador.organic_amount != null && (
                                        <span>· {fmtBRL(sugador.organic_amount)} de receita orgânica</span>
                                    )}
                                </div>
                            )}

                            <p className="text-white/30 text-[10px] leading-relaxed">
                                {adgroupInfo.adGroupType === 'CATALOG' && 'Adgroup de catálogo — 1 anúncio de catálogo agregado.'}
                                {adgroupInfo.adGroupType === 'ITEM'    && 'Adgroup do tipo ITEM — 1 anúncio único.'}
                                {adgroupInfo.adGroupType === 'FAMILY'  && 'Adgroup do tipo FAMILY — agrupa variações (N anúncios).'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Painel "Movido para SGI X" — fica permanente embaixo do status
                quando o analista já marcou move. Memória do audit fora do timeline. */}
            {sugador.status === 'movido' && sugador.campanha_destino_nome && (
                <div className="card-ecf rounded-xl p-4 mb-4 border-ecf-yellow/30 bg-ecf-yellow/[0.04]">
                    <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-ecf-yellow/15 border border-ecf-yellow/30 flex items-center justify-center shrink-0">
                            <ArrowRightLeft size={14} className="text-ecf-yellow" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-white/90 text-sm">
                                <span className="text-white/50">Marcado como movido para</span>
                                {' '}
                                <b className="text-ecf-yellow">{sugador.campanha_destino_nome}</b>
                            </p>
                            <p className="text-white/40 text-[11px] mt-0.5">
                                {sugador.movido_em && (
                                    <>em {fmtDateTime(sugador.movido_em)}</>
                                )}
                                {sugador.movido_por && (
                                    <> · por <span className="text-white/60">{sugador.movido_por.name}</span></>
                                )}
                                {sugador.campanha_destino_id && (
                                    <> · campaign_id <span className="font-mono">{sugador.campanha_destino_id}</span></>
                                )}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Drilldown MLBs — auto-load via API MCP do Adman (quick 260626-qgf).
                `mlbsHint` recebe a lista do controller (provider ML) para mostrar
                contagem instantanea enquanto o fetch de metricas detalhadas roda. */}
            {sugador.tipo === 'adgroup' && (
                <MlbsDoAdgroup
                    sugadorId={sugador.id}
                    adgroupName={sugador.adgroup_name}
                    companyId={sugador.company?.id}
                    mlbsHint={mlbs}
                />
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

            {showMoveModal && sugador.company && (
                <MoveToSgiModal
                    company={sugador.company}
                    sugadorIds={[sugador.id]}
                    onClose={() => setShowMoveModal(false)}
                    onSuccess={() => { /* o reload do Inertia já refresca a página */ }}
                />
            )}
        </AppLayout>
    );
}

/**
 * Drilldown que lista os MLBs da campanha do adgroup-sugador (via MCP da Adman).
 * MCP retorna métricas MLB-level confiáveis (ads vs orgânico, direto vs indireto)
 * que a REST legada não tem — daí ser carregado on-demand, e não no SSR.
 * O backend marca `matches_adgroup` por heurística de título; mostramos esses
 * em destaque pra ajudar o analista a focar.
 */
function MlbsDoAdgroup({ sugadorId, adgroupName, companyId, mlbsHint = [] }) {
    // Quick task 260626-qgf — Fluxo dual de fonte de dados:
    //   1. mlbsHint (ML, AdgroupMlbMapRepository) e a fonte CANONICA de IDs.
    //      Sempre disponivel via Inertia prop, instantaneo.
    //   2. Auto-load Adman MCP (/sugadores/{id}/mlbs) ENRIQUECE com metricas
    //      detalhadas (investimento, vendas, ACOS, thumbnail) quando o sugador
    //      e' de empresa com adman_account_id. Empresas ML-only (caso ByMobille
    //      Teste #298) caem em 422 — supressao silenciosa, lista ML simples.
    const [state, setState] = useState({ loading: false, error: null, data: null, loaded: false });
    const [copied, setCopied] = useState(false);

    const load = async () => {
        setState(s => ({ ...s, loading: true, error: null }));
        try {
            const res = await fetch(route('sugadores.mlbs', sugadorId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const body = await res.json().catch(() => null);
            if (!res.ok) {
                throw new Error(body?.reason || `HTTP ${res.status}`);
            }
            setState({ loading: false, error: null, data: body, loaded: true });
        } catch (e) {
            setState({ loading: false, error: e.message || 'Falha ao carregar', data: null, loaded: true });
        }
    };

    // Auto-load: dispara assim que o componente monta.
    useEffect(() => {
        if (sugadorId) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sugadorId]);

    // Indexa metricas Adman por mlb_id pra lookup O(1) ao montar items canonicos.
    const admanByMlb = (() => {
        const map = {};
        for (const m of (state.data?.mlbs ?? [])) {
            if (m && m.mlb_id) map[m.mlb_id] = m;
        }
        return map;
    })();

    // IDs canonicos: ML hint quando disponivel, fallback Adman (sugadores legados
    // sem adgroup_id resolvido ou empresas que ainda nao rodaram analise ML).
    const idsFromMl    = Array.isArray(mlbsHint) ? mlbsHint.filter(Boolean) : [];
    const idsFromAdman = Object.keys(admanByMlb);
    const allIds       = idsFromMl.length > 0 ? idsFromMl : idsFromAdman;

    // Items finais para render: 1 por ID, com metricas Adman se houver, senao
    // entry minima (so id + URL ML pro link funcionar).
    const items = allIds.map((id) => admanByMlb[id] ?? {
        mlb_id:     id,
        listing_id: id,
        title:      null,
        permalink:  mlbUrl(id),
    });

    // Copia lista de MLBs separados por vírgula.
    const handleCopy = async () => {
        if (!allIds.length) return;
        const ok = await copyToClipboard(allIds.join(','));
        if (ok) {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    // Sugador ML-only (caso comum pos quick 260626-qgf): API Adman responde 422
    // "Empresa sem adman_account_id". Nao mostrar como erro — temos lista ML.
    const isMlOnlyEmpresa = state.error && idsFromMl.length > 0;

    // Phase 30 Plan 30-04 — formata "Atualizado em ..." pra UI (so usado quando
    // Adman responde com sucesso e devolve metadado de sync).
    const fmtSyncTime = (iso) => {
        if (!iso) return null;
        try {
            const d = new Date(iso);
            return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        } catch {
            return iso;
        }
    };
    const syncedAt = fmtSyncTime(state.data?.last_synced_at);
    const isFresh  = !!state.data?.is_fresh;

    return (
        <div className="card-ecf rounded-xl p-5 mb-4">
            <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div className="flex items-center gap-2">
                    <Package size={14} className="text-white/40" />
                    <h2 className="text-white font-display font-semibold text-base">MLBs neste adgroup</h2>
                    {/* Contagem reflete IDs canonicos (ML hint ou Adman, na ordem). */}
                    {items.length > 0 && (
                        <span className="text-white/40 text-xs">· {items.length}</span>
                    )}
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                    {/* Quick 260626-qgf — unico botao da secao: copia todos os MLBs
                        separados por virgula. Visivel desde o 1o render via mlbsHint. */}
                    {allIds.length > 0 && (
                        <button
                            type="button"
                            onClick={handleCopy}
                            className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-xs"
                            title={`Copia ${allIds.length} MLB${allIds.length !== 1 ? 's' : ''} separados por vírgula`}
                        >
                            {copied ? <Check size={12} className="text-emerald-300" /> : <Copy size={12} />}
                            {copied ? 'Copiado!' : `Copiar todos (${allIds.length})`}
                        </button>
                    )}
                </div>
            </div>

            {state.loading && (
                <div className="flex items-center gap-2 text-white/50 text-sm py-4">
                    <Loader2 size={14} className="animate-spin" />
                    Carregando MLBs...
                </div>
            )}

            {/* Quick 260626-qgf — Bloco de freshness so' aparece quando o Adman
                MCP respondeu OK (state.data presente E syncedAt resolvido).
                Empresas ML-only caem em 422 — state.data e' null, syncedAt e'
                null, e a mensagem "Dado defasado" nao tem sentido (info de sync
                pertence ao Adman, ML traz dado em tempo real direto da API). */}
            {state.data && syncedAt && companyId && (
                <div className="flex items-start gap-2 p-2.5 rounded-lg border border-white/[0.06] bg-white/[0.02] mb-3 flex-wrap">
                    <div className="flex-1 min-w-0 text-[11px] leading-relaxed">
                        {state.data?.empty_state === 'never_synced' ? (
                            <p className="text-amber-300">
                                ⓘ Esta empresa ainda não foi sincronizada (Adman).
                            </p>
                        ) : state.data?.empty_state === 'synced_no_mlbs' ? (
                            <p className="text-white/60">
                                Nenhum MLB encontrado neste adgroup no período (Adman). Última sincronização: <b className="text-white/80">{syncedAt}</b>.
                            </p>
                        ) : (
                            <p className={isFresh ? 'text-emerald-300' : 'text-amber-300'}>
                                {isFresh ? '✓ Dado atualizado' : '⚠ Dado defasado'} · última sincronização em <b className="text-white/90">{syncedAt}</b>
                                {state.data?.mlbs_in_cache > 0 && (
                                    <span className="text-white/40"> · {state.data.mlbs_in_cache} MLB{state.data.mlbs_in_cache !== 1 ? 's' : ''} totais cacheados nesta empresa</span>
                                )}
                            </p>
                        )}
                    </div>
                </div>
            )}

            {/* Caixa de erro suprimida quando temos lista ML — empresa ML-only
                e' caso valido, nao falha (422 esperado). Mostra erro real apenas
                quando NAO ha mlbsHint pra cair de pe (sugadores 100% Adman). */}
            {state.error && !isMlOnlyEmpresa && (
                <div className="flex items-start gap-2 p-3 rounded-lg border border-red-500/20 bg-red-500/[0.05]">
                    <AlertTriangle size={14} className="text-red-400 shrink-0 mt-0.5" />
                    <p className="text-red-300 text-xs leading-relaxed">{state.error}</p>
                </div>
            )}

            {state.loaded && !state.error && items.length === 0 && (
                <p className="text-white/40 text-sm">
                    Nenhum MLB encontrado neste adgroup no período.
                </p>
            )}

            {items.length > 0 && (
                <ul className="space-y-3">
                    {items.map(m => <MlbRow key={m.mlb_id || m.listing_id} mlb={m} />)}
                </ul>
            )}
        </div>
    );
}

// Quick task 260626-qgf — helper para gerar URL canonica do anuncio ML a partir
// do mlb_id (formato MLB-123456). Espelha Sugador::urlAnuncioML() no PHP, mantido
// no JS para items que vem so do hint ML (sem permalink do Adman).
const mlbUrl = (mlbId) => {
    if (!mlbId) return null;
    const digits = String(mlbId).replace(/\D/g, '');
    if (!digits) return null;
    return `https://produto.mercadolivre.com.br/MLB-${digits}`;
};

function MlbRow({ mlb }) {
    // Items ML-only nao trazem metricas Adman (investment/ads_revenue/acos etc).
    // Detecta o caso pra renderizar versao compacta — evita poluir UI com
    // "R$ 0,00" pra tudo quando o sugador e' de empresa ML-only.
    const hasAdmanMetrics = mlb.investment != null || mlb.ads_revenue != null
        || mlb.ads_sold_quantity != null || mlb.clicks != null;
    const listingId = mlb.mlb_id || mlb.listing_id;

    return (
        <li className="flex gap-3 p-3 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.04] transition-colors">
            {mlb.image_url ? (
                <img
                    src={mlb.image_url}
                    alt={mlb.title || ''}
                    className="w-14 h-14 rounded-md object-cover border border-white/[0.08] shrink-0"
                    onError={(e) => { e.currentTarget.style.display = 'none'; }}
                />
            ) : (
                <div className="w-14 h-14 rounded-md bg-white/[0.04] border border-white/[0.08] flex items-center justify-center shrink-0">
                    <ImageIcon size={16} className="text-white/30" />
                </div>
            )}

            <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-2 mb-1">
                    <div className="min-w-0 flex-1">
                        <p className="text-white/90 text-sm font-medium truncate" title={mlb.title}>{mlb.title || listingId}</p>
                        <p className="text-white/40 text-[11px] font-mono">
                            {listingId}
                            {mlb.sku && <span className="text-white/30"> · SKU {mlb.sku}</span>}
                            {mlb.curve && <span className="text-white/30"> · Curva {mlb.curve}</span>}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {mlb.permalink && (
                            <a
                                href={mlb.permalink}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-white/40 hover:text-white"
                                title="Abrir no Mercado Livre"
                            >
                                <ExternalLink size={13} />
                            </a>
                        )}
                    </div>
                </div>

                {hasAdmanMetrics && (
                    <>
                        <div className="grid grid-cols-2 md:grid-cols-6 gap-x-3 gap-y-1 text-[11px] mt-1">
                            <MlbStat label="Invest." value={fmtBRL(mlb.investment)} color="red" />
                            <MlbStat label="Vendas ads" value={fmtInt(mlb.ads_sold_quantity)} color={mlb.ads_sold_quantity > 0 ? 'green' : 'white'} />
                            <MlbStat label="Receita ads" value={fmtBRL(mlb.ads_revenue)} color="green" />
                            <MlbStat label="Direta/Indir." value={`${fmtInt(mlb.ads_sold_direct)} / ${fmtInt(mlb.ads_sold_indirect)}`} />
                            <MlbStat label="ACOS" value={fmtPct(mlb.acos)} color="yellow" />
                            <MlbStat label="Cliques" value={fmtInt(mlb.clicks)} />
                        </div>

                        {(mlb.organic_sold_quantity > 0 || mlb.organic_revenue > 0) && (
                            <p className="text-white/40 text-[10px] mt-1.5">
                                Orgânico no período: {fmtInt(mlb.organic_sold_quantity)} venda{mlb.organic_sold_quantity === 1 ? '' : 's'} · {fmtBRL(mlb.organic_revenue)}
                            </p>
                        )}
                    </>
                )}
            </div>
        </li>
    );
}

function MlbStat({ label, value, color = 'white' }) {
    const colors = {
        white:  'text-white/80',
        yellow: 'text-ecf-yellow',
        red:    'text-red-300',
        green:  'text-emerald-300',
    };
    return (
        <div className="flex flex-col">
            <span className="text-white/30 uppercase tracking-wider text-[9px]">{label}</span>
            <span className={cn('tabular-nums font-medium', colors[color])}>{value}</span>
        </div>
    );
}
