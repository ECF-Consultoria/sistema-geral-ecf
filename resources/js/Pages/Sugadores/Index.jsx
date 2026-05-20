import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangle, Building2, ChevronLeft, ChevronRight,
    PlayCircle, Filter, X, Megaphone, Tag, ListTree,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Constantes de UI ──────────────────────────────────────────────────────
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

const TIPO_LABELS = { adgroup: 'Adgroup', campanha: 'Campanha' };
const TIPO_ICONS  = { adgroup: Tag, campanha: Megaphone };

const MOTIVO_LABELS = {
    gasto_sem_venda:         'Gasto sem venda',
    cpc_alto:                'CPC alto',
    acos_alto:               'ACOS alto',
    cliques_sem_conversao:   'Cliques sem conversão',
    pct_anuncios_sugadores:  '% adgroups sugadores',
};

const ACAO_LABELS = {
    pausado:        'Pausado no ML',
    removido:       'Removido',
    reduzido_lance: 'Reduzido lance',
    reativado:      'Reativado',
    outro:          'Outro',
};

// ─── Helpers ───────────────────────────────────────────────────────────────
const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const fmtPct = (n) => n == null ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%';
// Aceita "YYYY-MM-DD" e também ISO datetime ("YYYY-MM-DDTHH:mm:ss.SSSZ") do cast 'date' do Laravel.
// Fixa em meia-noite local para não cair no dia anterior por causa de timezone.
const fmtDate = (d) => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR') : '—';

// ─── Componentes locais ────────────────────────────────────────────────────

function StatusBadge({ status }) {
    return (
        <span className={cn('inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border', STATUS_BADGE[status])}>
            {STATUS_LABELS[status] || status}
        </span>
    );
}

function MotivoBadge({ motivo }) {
    return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-white/[0.05] text-white/70 border border-white/[0.08]">
            {MOTIVO_LABELS[motivo] || motivo}
        </span>
    );
}

function NativeSelect({ value, onChange, placeholder, options, className }) {
    return (
        <select
            value={value || ''}
            onChange={e => onChange(e.target.value)}
            className={cn(
                'h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer',
                className
            )}
        >
            {placeholder && <option value="">{placeholder}</option>}
            {options.map(o => (
                <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>
            ))}
        </select>
    );
}

function StatusUpdateModal({ sugador, onClose }) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        status:      'em_acao',
        acao_tomada: '',
        observacao:  '',
    });

    function submit(e) {
        e.preventDefault();
        patch(route('sugadores.update-status', sugador.id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-start justify-between mb-4">
                    <div>
                        <h3 className="text-white font-display font-bold text-lg">Atualizar status</h3>
                        <p className="text-white/40 text-xs mt-0.5">{sugador.company?.name}</p>
                    </div>
                    <button onClick={onClose} className="text-white/40 hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Novo status</label>
                        <NativeSelect
                            value={data.status}
                            onChange={v => setData('status', v)}
                            options={[
                                { value: 'em_acao',   label: 'Em ação' },
                                { value: 'resolvido', label: 'Resolvido' },
                                { value: 'ignorado',  label: 'Ignorado' },
                                { value: 'pendente',  label: 'Voltar para pendente' },
                            ]}
                            className="w-full"
                        />
                        {errors.status && <p className="text-red-400 text-xs mt-1">{errors.status}</p>}
                    </div>

                    {data.status === 'resolvido' && (
                        <div>
                            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Ação tomada no ML</label>
                            <NativeSelect
                                value={data.acao_tomada}
                                onChange={v => setData('acao_tomada', v)}
                                placeholder="— selecione —"
                                options={Object.entries(ACAO_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                                className="w-full"
                            />
                        </div>
                    )}

                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Observação</label>
                        <textarea
                            value={data.observacao}
                            onChange={e => setData('observacao', e.target.value)}
                            rows={3}
                            placeholder="Notas sobre a ação tomada (opcional)"
                            className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                        />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold"
                        >
                            {processing ? 'Salvando...' : 'Confirmar'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ─── Página principal ──────────────────────────────────────────────────────

export default function SugadoresIndex({ sugadores, companies, users = [], filters, total_pendentes, can_manage, can_analyze }) {
    const [f, setF] = useState({
        company_id:       filters?.company_id || '',
        user_id:          filters?.user_id || '',
        status:           filters?.status || '',
        tipo:             filters?.tipo || '',
        date_from:        filters?.date_from || '',
        date_to:          filters?.date_to || '',
        include_resolved: filters?.include_resolved ? '1' : '',
    });
    const [showFilters, setShowFilters] = useState(false);
    const [actionTarget, setActionTarget] = useState(null);
    const [analyzing, setAnalyzing] = useState(false);

    function applyFilters(updates = {}) {
        const merged = { ...f, ...updates };
        setF(merged);
        const clean = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== '' && v != null));
        router.get(route('sugadores.index'), clean, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        setF({ company_id: '', user_id: '', status: '', tipo: '', date_from: '', date_to: '', include_resolved: '' });
        router.get(route('sugadores.index'), {}, { preserveState: true });
    }

    function runAnalysis() {
        if (!confirm('Rodar análise para TODAS as empresas com config ativa? Isso pode levar alguns minutos.')) return;
        setAnalyzing(true);
        router.post(route('sugadores.analyze-all'), {}, {
            preserveScroll: true,
            onFinish: () => setAnalyzing(false),
        });
    }

    const list = sugadores?.data ?? [];
    const meta = sugadores ?? { current_page: 1, last_page: 1, links: [] };
    const hasAnyFilter = Object.values(f).some(v => v && v !== '');

    return (
        <AppLayout title="Sugadores">
            <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-white font-display font-bold text-2xl">Sugadores</h1>
                        {total_pendentes > 0 && (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-red-500/15 border border-red-500/30 text-red-300 text-xs font-bold">
                                <AlertTriangle size={12} />
                                {fmtInt(total_pendentes)} pendente{total_pendentes !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                    <p className="text-white/40 text-sm mt-1">Adgroups (e opcionalmente campanhas) drenando investimento sem retorno</p>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setShowFilters(s => !s)}
                        className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                    >
                        <Filter size={14} />
                        Filtros
                        {hasAnyFilter && <span className="w-1.5 h-1.5 rounded-full bg-ecf-yellow" />}
                    </button>
                    {can_analyze && (
                        <button
                            onClick={runAnalysis}
                            disabled={analyzing}
                            className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-60 text-[13px] font-bold"
                        >
                            <PlayCircle size={14} />
                            {analyzing ? 'Analisando...' : 'Rodar análise'}
                        </button>
                    )}
                </div>
            </div>

            {/* Filtros */}
            {showFilters && (
                <div className="card-ecf rounded-xl p-4 mb-4">
                    <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
                        <NativeSelect
                            value={f.company_id}
                            onChange={v => applyFilters({ company_id: v })}
                            placeholder="Todas empresas"
                            options={companies.map(c => ({ value: c.id, label: c.name }))}
                            className="w-full"
                        />
                        {can_manage && users.length > 0 && (
                            <NativeSelect
                                value={f.user_id}
                                onChange={v => applyFilters({ user_id: v })}
                                placeholder="Responsável (qualquer)"
                                options={users.map(u => ({ value: u.id, label: u.name }))}
                                className="w-full"
                            />
                        )}
                        <NativeSelect
                            value={f.status}
                            onChange={v => applyFilters({ status: v })}
                            placeholder="Todos status"
                            options={Object.entries(STATUS_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                            className="w-full"
                        />
                        <NativeSelect
                            value={f.tipo}
                            onChange={v => applyFilters({ tipo: v })}
                            placeholder="Todos tipos"
                            options={Object.entries(TIPO_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                            className="w-full"
                        />
                        <input
                            type="date"
                            value={f.date_from}
                            onChange={e => applyFilters({ date_from: e.target.value })}
                            className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            placeholder="De"
                        />
                        <input
                            type="date"
                            value={f.date_to}
                            onChange={e => applyFilters({ date_to: e.target.value })}
                            className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            placeholder="Até"
                        />
                    </div>
                    <div className="mt-3 flex items-center justify-between gap-3 flex-wrap">
                        <label className="inline-flex items-center gap-2 text-white/70 text-xs cursor-pointer select-none">
                            <input
                                type="checkbox"
                                checked={!!f.include_resolved}
                                onChange={e => applyFilters({ include_resolved: e.target.checked ? '1' : '' })}
                                className="w-3.5 h-3.5 accent-ecf-yellow cursor-pointer"
                            />
                            Incluir resolvidos na listagem
                        </label>
                        {hasAnyFilter && (
                            <button onClick={clearFilters} className="text-white/50 hover:text-white text-xs underline">
                                Limpar filtros
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Aviso de resolvidos ocultos (só quando nenhum status específico está filtrado) */}
            {!f.status && !f.include_resolved && (
                <p className="text-white/40 text-[11px] mb-3 -mt-1 px-1">
                    Sugadores <b className="text-emerald-300/80">resolvidos</b> estão ocultos. Use o filtro <b className="text-white/60">Incluir resolvidos</b> pra ver.
                </p>
            )}

            {/* Tabela */}
            <div className="card-ecf rounded-xl overflow-hidden">
                {list.length === 0 ? (
                    <div className="p-12 text-center">
                        <ListTree size={32} className="mx-auto text-white/20 mb-3" />
                        <p className="text-white/60 text-sm font-medium">Nenhum sugador encontrado.</p>
                        <p className="text-white/30 text-xs mt-1">
                            {hasAnyFilter
                                ? 'Tente limpar os filtros.'
                                : can_manage
                                    ? 'Configure thresholds em uma empresa e rode a análise.'
                                    : 'Aguarde a próxima análise diária.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-white/[0.02] border-b border-white/[0.06]">
                                <tr>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Empresa</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Tipo</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Nome / ID</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Investimento</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Vendas</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">CPC</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">ACOS</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Motivos</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Status</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Detectado</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {list.map(s => {
                                    const TipoIcon = TIPO_ICONS[s.tipo] || Tag;
                                    const isPendente = s.status === 'pendente';
                                    return (
                                        <tr
                                            key={s.id}
                                            onClick={() => router.visit(route('sugadores.show', s.id))}
                                            className={cn(
                                                'border-b border-white/[0.04] hover:bg-white/[0.04] transition-colors cursor-pointer',
                                                isPendente && 'bg-red-500/[0.02]'
                                            )}
                                        >
                                            <td className="px-4 py-3 text-[13px] text-white/80">
                                                <div className="flex items-center gap-2">
                                                    <Building2 size={12} className="text-white/30 shrink-0" />
                                                    <span className="truncate max-w-[160px]">{s.company?.name || '—'}</span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5 text-[12px] text-white/70">
                                                    <TipoIcon size={12} className="text-white/40" />
                                                    {TIPO_LABELS[s.tipo] || s.tipo}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-white/70 max-w-[260px]">
                                                {s.tipo === 'campanha' ? (
                                                    <div className="truncate">{s.campaign_name || s.campaign_id}</div>
                                                ) : (
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        {s.thumbnail ? (
                                                            <img
                                                                src={s.thumbnail}
                                                                alt=""
                                                                loading="lazy"
                                                                className="w-9 h-9 rounded-md object-cover bg-white/[0.05] border border-white/[0.06] shrink-0"
                                                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                                                            />
                                                        ) : (
                                                            <div className="w-9 h-9 rounded-md bg-white/[0.04] border border-white/[0.06] shrink-0 flex items-center justify-center">
                                                                <Tag size={12} className="text-white/30" />
                                                            </div>
                                                        )}
                                                        <div className="min-w-0">
                                                            <div className="truncate">{s.adgroup_name || s.adgroup_id || s.campaign_id}</div>
                                                            {(s.adgroup_type || s.catalog_listing) && (
                                                                <div className="text-[10px] text-white/40 mt-0.5">
                                                                    {s.catalog_listing ? 'Catálogo' : (s.adgroup_type || '').toLowerCase()}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[13px] text-white/80 font-medium tabular-nums">
                                                {fmtBRL(s.investimento_periodo)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[13px] text-white/80 tabular-nums">
                                                {fmtInt(s.vendas_periodo)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[12px] text-white/60 tabular-nums">
                                                {s.cpc_medio == null ? '—' : 'R$ ' + Number(s.cpc_medio).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[12px] text-white/60 tabular-nums">
                                                {fmtPct(s.acos)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1 max-w-[200px]">
                                                    {(s.motivos || []).slice(0, 2).map(m => (
                                                        <MotivoBadge key={m} motivo={m} />
                                                    ))}
                                                    {(s.motivos || []).length > 2 && (
                                                        <span className="text-white/40 text-[10px] font-semibold">+{s.motivos.length - 2}</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={s.status} />
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-white/50">
                                                {fmtDate(s.reference_date)}
                                            </td>
                                            <td className="px-4 py-3 text-right" onClick={e => e.stopPropagation()}>
                                                <div className="inline-flex items-center gap-1">
                                                    {isPendente && (
                                                        <button
                                                            onClick={() => setActionTarget(s)}
                                                            className="px-2 py-1 rounded text-[11px] font-semibold bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/25"
                                                        >
                                                            Agir
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Paginação */}
                {meta.last_page > 1 && (
                    <div className="flex items-center justify-between px-4 py-3 border-t border-white/[0.04]">
                        <p className="text-white/50 text-xs">
                            Página {meta.current_page} de {meta.last_page} · {fmtInt(meta.total)} registro{meta.total !== 1 ? 's' : ''}
                        </p>
                        <div className="flex items-center gap-1">
                            {meta.links?.map((link, i) => {
                                if (!link.url) return null;
                                const labelPlain = link.label.replace(/<[^>]+>/g, '').trim();
                                const isPrev = link.label.includes('Previous') || link.label.includes('&laquo;');
                                const isNext = link.label.includes('Next')     || link.label.includes('&raquo;');
                                return (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        preserveState
                                        preserveScroll
                                        className={cn(
                                            'inline-flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg text-[12px] font-semibold border',
                                            link.active
                                                ? 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30'
                                                : 'text-white/60 hover:text-white border-white/[0.08] hover:bg-white/[0.05]'
                                        )}
                                    >
                                        {isPrev ? <ChevronLeft size={14} /> : isNext ? <ChevronRight size={14} /> : labelPlain}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {actionTarget && (
                <StatusUpdateModal sugador={actionTarget} onClose={() => setActionTarget(null)} />
            )}
        </AppLayout>
    );
}
